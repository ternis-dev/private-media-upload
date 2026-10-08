<?php

declare(strict_types=1);

// M1 lean router (no framework). Laravel migration in M2+ per ADR-0001.
// Run: php -S localhost:8000 -t apps/api/public  (or composer serve in apps/api)
// Endpoints: /health, /v1/tiers, /v1/uploads/init, PUT|POST /v1/uploads/{id},
//   POST /v1/uploads/{id}/complete, POST /v1/uploads/l1-complete,
//   GET /v1/shares/{id}/meta, GET /v1/blobs/..., GET /s/{id} (302).

require __DIR__ . '/../vendor/autoload.php';

use PrivateWf\Api\Drivers;
use PrivateWf\Api\HttpRange;
use PrivateWf\Api\Shares;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\R2S3Driver;

header('X-Robots-Tag: noindex, nofollow');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

$json = static function (mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
};

$store = Store::open(Drivers::dbPath(), Drivers::varDir());
$svc = new UploadService($store, Drivers::webUrl());

if ($method === 'GET' && $path === '/health') {
    $json(['ok' => true, 'service' => 'private-wf-api', 'm' => 'M1']);
    return;
}

if ($method === 'GET' && $path === '/v1/tiers') {
    $json(['tiers' => [
        ['id' => 'L1', 'name' => 'Edge Object Storage', 'residency' => 'global-edge', 'badge' => Shares::badge('L1'), 'backend' => 'Cloudflare R2 (S3-compatible)'],
        ['id' => 'L2', 'name' => 'Provider Vault DE', 'residency' => 'DE-only', 'badge' => Shares::badge('L2'), 'backend' => 'SFTP vault (single location)'],
        ['id' => 'L3', 'name' => 'Sovereign Node', 'residency' => 'DE-only', 'badge' => Shares::badge('L3'), 'backend' => 'own server (local disk)'],
    ]]);
    return;
}

// Reserve an upload. L1 real mode also returns a SigV4 presigned PUT for browser-direct.
if ($method === 'POST' && $path === '/v1/uploads/init') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    try {
        $r = $svc->reserve(
            (string) ($body['tier'] ?? ''), (string) ($body['filename'] ?? ''),
            (int) ($body['size'] ?? 0), (string) ($body['mime'] ?? 'application/octet-stream'));
    } catch (\InvalidArgumentException $e) {
        $json(['error' => $e->getMessage()], 422);
        return;
    }
    $res = [
        'uploadId' => $r['uploadId'], 'key' => $r['key'], 'tier' => $r['tier'],
        'expected' => $r['expected'],
        'appendUrl' => Drivers::appUrl() . '/v1/uploads/' . $r['uploadId'],
    ];
    if ($r['tier'] === 'L1') {
        $r2 = Drivers::forTier('L1');
        assert($r2 instanceof R2S3Driver);
        try {
            $r2->ensureBucket();
            $res['mode'] = $r2->isReal() ? 's3-direct' : 'proxy-via-append (dev emulation)';
            $res['presignedPutUrl'] = $r2->presignedPutUrl($r['key'], (string) ($body['mime'] ?? 'application/octet-stream'));
        } catch (\Throwable $e) {
            $json(['error' => 'L1 storage unreachable: ' . $e->getMessage()], 502);
            return;
        }
    } else {
        $res['mode'] = 'chunked-append';
    }
    $json($res, 201);
    return;
}

// Append raw bytes to a staged upload (repeatable; 4–10 MB chunks recommended).
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/v1/uploads/(up_[0-9A-Za-z]{8,32})$#', $path, $m)) {
    try {
        $r = $svc->append($m[1], file_get_contents('php://input') ?: '');
        $json(['received' => $r['received'], 'expected' => $r['expected'], 'done' => $r['received'] === $r['expected']]);
    } catch (\RuntimeException $e) {
        $code = str_contains($e->getMessage(), 'exceeds') ? 413 : 404;
        $json(['error' => $e->getMessage()], $code);
    }
    return;
}

// Finalize a staged upload (single streaming putFile into the tier driver).
if ($method === 'POST' && preg_match('#^/v1/uploads/(up_[0-9A-Za-z]{8,32})/complete$#', $path, $m)) {
    $up = $store->getUpload($m[1]);
    if ($up === null) {
        $json(['error' => 'unknown upload'], 404);
        return;
    }
    try {
        $json($svc->complete($m[1], Drivers::forTier($up['tier'])), 201);
    } catch (\RuntimeException $e) {
        $json(['error' => $e->getMessage()], 422);
    }
    return;
}

// Finalize a browser-direct-to-R2 upload (bytes already in R2; server verifies).
if ($method === 'POST' && $path === '/v1/uploads/l1-complete') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $key = (string) ($body['key'] ?? '');
    if (!str_starts_with($key, 'u/l1/') || str_contains($key, '..')) {
        $json(['error' => 'key must be a reserved u/l1/... key'], 422);
        return;
    }
    $r2 = Drivers::forTier('L1');
    assert($r2 instanceof R2S3Driver);
    try {
        $json($svc->completeL1($key, (string) ($body['filename'] ?? ''), (int) ($body['size'] ?? 0),
            (string) ($body['mime'] ?? 'application/octet-stream'), $r2), 201);
    } catch (\InvalidArgumentException $e) {
        $json(['error' => $e->getMessage()], 422);
    } catch (\RuntimeException $e) {
        $json(['error' => $e->getMessage()], 422);
    }
    return;
}

if ($method === 'GET' && preg_match('#^/v1/shares/([0-9A-Za-z]{8,32})/meta$#', $path, $m)) {
    $meta = $svc->meta($m[1]);
    if ($meta === null) {
        $json(['error' => 'unknown share'], 404);
        return;
    }
    if ($meta['expired']) {
        $json(['error' => 'share expired', 'id' => $meta['id']], 410);
        return;
    }
    $json($meta);
    return;
}

// Stream bytes (L2/L3 + L1 emulation) with HMAC + Range. L1 real mode redirects to R2.
if ($method === 'GET' && str_starts_with($path, '/v1/blobs/')) {
    $key = implode('/', array_map('rawurldecode', explode('/', substr($path, strlen('/v1/blobs/')))));
    if (!preg_match('#^u/(l1|l2|l3)/#', $key, $tm) || str_contains($key, '..')) {
        $json(['error' => 'invalid key'], 400);
        return;
    }
    $tier = strtoupper($tm[1]);
    $driver = Drivers::forTier($tier);
    $expires = (int) ($_GET['expires'] ?? 0);
    $sig = (string) ($_GET['sig'] ?? '');
    if (!$driver->verifySignedUrl($key, $expires, $sig)) {
        $json(['error' => 'bad or expired signature'], 403);
        return;
    }
    if ($tier === 'L1' && $driver instanceof R2S3Driver && $driver->isReal()) {
        header('Location: ' . $driver->signedGetUrl($key, 300), true, 302);
        return;
    }
    /** @var \PrivateWf\Storage\LocalSovereignDriver|\PrivateWf\Storage\SftpVaultDriver|\PrivateWf\Storage\R2S3Driver $driver */
    $fsPath = $driver->localPath($key);
    if (!is_file($fsPath)) {
        $json(['error' => 'blob gone (expired/purged?)'], 410);
        return;
    }
    $size = filesize($fsPath);
    $asset = $store->getAssetByKey($key);
    $mime = $asset !== null ? $asset['mime'] : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment');
    header('Accept-Ranges: bytes');
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    if ($rangeHeader !== '') {
        $range = HttpRange::parse($rangeHeader, $size);
        if ($range === null) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            return;
        }
        http_response_code(206);
        $len = $range['end'] - $range['start'] + 1;
        header("Content-Range: bytes {$range['start']}-{$range['end']}/{$size}");
        header("Content-Length: {$len}");
        $fh = fopen($fsPath, 'rb');
        fseek($fh, $range['start']);
        $left = $len;
        while ($left > 0 && !feof($fh)) {
            echo fread($fh, (int) min(8192, $left));
            $left -= 8192;
            if ($left % 1048576 < 8192) {
                flush();
            }
        }
        fclose($fh);
        return;
    }
    header("Content-Length: {$size}");
    $fh = fopen($fsPath, 'rb');
    while (!feof($fh)) {
        echo fread($fh, 8192);
    }
    fclose($fh);
    return;
}

// Short link: 302 to the tier-appropriate signed URL.
if ($method === 'GET' && preg_match('#^/s/([0-9A-Za-z]{8,32})$#', $path, $m)) {
    $meta = $svc->meta($m[1]);
    if ($meta === null) {
        $json(['error' => 'unknown share'], 404);
        return;
    }
    if ($meta['expired']) {
        $json(['error' => 'share expired'], 410);
        return;
    }
    $row = $store->getShare($m[1]);
    header('Location: ' . Drivers::forTier($meta['tier'])->signedGetUrl($row['storage_key'], 900), true, 302);
    return;
}

$json(['error' => 'not found', 'see' => 'GET /health, GET /v1/tiers'], 404);
