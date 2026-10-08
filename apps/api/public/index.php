<?php

declare(strict_types=1);

// M2a lean router (no framework). Laravel migration in M2b per ADR-0001.
// Run: php -S localhost:8000 -t apps/api/public  (or composer serve in apps/api)
// Security model: unguessable share ids + rate limits + optional argon2id
// password + max-views/burn + HMAC blob URLs + PII-minimized audit log.
// Views are consumed at blob serve time — EXCEPT L1-real, where the bytes
// never touch PHP, so /s/:id consumes at redirect instead.

require __DIR__ . '/../vendor/autoload.php';

use PrivateWf\Api\ClamAv;
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
$clam = ClamAv::fromEnv();
$svc = new UploadService($store, Drivers::webUrl(),
    $clam->enabled() ? $clam->scanFile(...) : null);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$hashId = static fn (string $v): string => hash_hmac('sha256', $v, Drivers::auditSalt());

/** Fixed-window gate. Returns true when allowed. */
$gate = static function (string $name, int $limit, int $window) use ($store, $ip, $json): bool {
    $r = $store->rateHit("{$name}:{$ip}", $limit, $window, time());
    header('X-RateLimit-Remaining: ' . $r['remaining']);
    if (!$r['allowed']) {
        header('Retry-After: ' . max(1, $r['reset'] - time()));
        $json(['error' => 'rate limited', 'retryAfter' => $r['reset']], 429);
        return false;
    }
    return true;
};

/** Share password from header (preferred) or query (leaks into logs — header first). */
$sharePassword = static function (): ?string {
    $h = $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? null;
    if ($h !== null && $h !== '') {
        return $h;
    }
    $q = $_GET['password'] ?? null;
    return $q !== null && $q !== '' ? (string) $q : null;
};

$audit = static function (?string $shareId, string $result) use ($store, $ip, $ua, $hashId): void {
    if ($shareId !== null) {
        $store->logAccess($shareId, $hashId($ip), $hashId($ua), $result, time());
    }
};

/** Map authorize() status → HTTP. Returns row on ok, else responds + null. */
$authorizeHttp = static function (?array $row, ?string $shareId) use ($json, $audit, $sharePassword): ?array {
    if ($row === null) {
        $json(['error' => 'unknown share'], 404);
        return null;
    }
    $st = UploadService::authorize($row, $sharePassword());
    if ($st === 'ok') {
        return $row;
    }
    $audit($shareId, match ($st) {
        'password-required', 'password-wrong' => 'bad-password',
        default => $st,
    });
    match ($st) {
        'not-found' => $json(['error' => 'unknown share'], 404),
        'revoked' => $json(['error' => 'share revoked'], 410),
        'expired' => $json(['error' => 'share expired'], 410),
        'exhausted' => $json(['error' => 'view limit reached'], 410),
        'password-required' => $json(['error' => 'password required', 'passwordRequired' => true], 401),
        default => $json(['error' => 'wrong password'], 401),
    };
    return null;
};

if ($method === 'GET' && $path === '/health') {
    $json(['ok' => true, 'service' => 'private-wf-api', 'm' => 'M2a',
        'scanner' => $clam->enabled() ? 'clamav' : 'skipped']);
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

/** Authenticated user row or null (Bearer pwf_…). */
$me = static function () use ($svc): ?array {
    return $svc->userFromToken(Drivers::bearer());
};

if ($method === 'POST' && $path === '/v1/auth/register') {
    if (!$gate('auth', 10, 3600)) {
        return;
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    try {
        $json($svc->register((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''),
            Drivers::defaultQuota()), 201);
    } catch (\InvalidArgumentException $e) {
        $json(['error' => $e->getMessage()], 422);
    } catch (\RuntimeException $e) {
        $json(['error' => $e->getMessage()], 409);
    }
    return;
}

if ($method === 'POST' && $path === '/v1/auth/login') {
    if (!$gate('login', 5, 60)) {
        return;
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    try {
        $json($svc->login((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''),
            (string) ($body['name'] ?? 'api')));
    } catch (\RuntimeException $e) {
        $json(['error' => 'invalid credentials'], 401); // generic: no enumeration
    }
    return;
}

if ($method === 'POST' && $path === '/v1/auth/logout') {
    $bearer = Drivers::bearer();
    if ($bearer !== null) {
        $store->revokeToken(hash('sha256', $bearer));
    }
    $json(['ok' => true]);
    return;
}

// Reserve an upload. L1 real mode also returns a SigV4 presigned PUT for browser-direct.
if ($method === 'POST' && $path === '/v1/uploads/init') {
    if (!$gate('init', 30, 3600)) {
        return;
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    try {
        $owner = $me();
        $r = $svc->reserve(
            (string) ($body['tier'] ?? ''), (string) ($body['filename'] ?? ''),
            (int) ($body['size'] ?? 0), (string) ($body['mime'] ?? 'application/octet-stream'),
            $owner['id'] ?? null, $owner !== null ? (int) $owner['quota_bytes'] : null);
    } catch (\InvalidArgumentException $e) {
        $json(['error' => $e->getMessage()], 422);
        return;
    } catch (\RuntimeException $e) {
        $json(['error' => $e->getMessage()], 413);
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
    if (!$gate('append', 600, 3600)) {
        return;
    }
    try {
        $r = $svc->append($m[1], file_get_contents('php://input') ?: '');
        $json(['received' => $r['received'], 'expected' => $r['expected'], 'done' => $r['received'] === $r['expected']]);
    } catch (\RuntimeException $e) {
        $code = str_contains($e->getMessage(), 'exceeds') ? 413 : 404;
        $json(['error' => $e->getMessage()], $code);
    }
    return;
}

// Finalize a staged upload (verify + scan + streaming putFile + mint share).
if ($method === 'POST' && preg_match('#^/v1/uploads/(up_[0-9A-Za-z]{8,32})/complete$#', $path, $m)) {
    if (!$gate('complete', 60, 3600)) {
        return;
    }
    $up = $store->getUpload($m[1]);
    if ($up === null) {
        $json(['error' => 'unknown upload'], 404);
        return;
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    try {
        $json($svc->complete($m[1], Drivers::forTier($up['tier']), [
            'password' => $body['password'] ?? null,
            'maxViews' => $body['maxViews'] ?? null,
            'burn' => $body['burn'] ?? false,
            'e2ee' => $body['e2ee'] ?? false,
        ]), 201);
    } catch (\InvalidArgumentException $e) {
        $json(['error' => $e->getMessage()], 422);
    } catch (\RuntimeException $e) {
        $json(['error' => $e->getMessage()], 422);
    }
    return;
}

// Finalize a browser-direct-to-R2 upload (bytes already in R2; server verifies).
if ($method === 'POST' && $path === '/v1/uploads/l1-complete') {
    if (!$gate('complete', 60, 3600)) {
        return;
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $key = (string) ($body['key'] ?? '');
    if (!str_starts_with($key, 'u/l1/') || str_contains($key, '..')) {
        $json(['error' => 'key must be a reserved u/l1/... key'], 422);
        return;
    }
    $r2 = Drivers::forTier('L1');
    assert($r2 instanceof R2S3Driver);
    try {
        $owner = $me();
        $json($svc->completeL1($key, (string) ($body['filename'] ?? ''), (int) ($body['size'] ?? 0),
            (string) ($body['mime'] ?? 'application/octet-stream'), $r2, [
                'password' => $body['password'] ?? null,
                'maxViews' => $body['maxViews'] ?? null,
                'burn' => $body['burn'] ?? false,
                'e2ee' => $body['e2ee'] ?? false,
            ], $owner['id'] ?? null), 201);
    } catch (\InvalidArgumentException $e) {
        $json(['error' => $e->getMessage()], 422);
    } catch (\RuntimeException $e) {
        $json(['error' => $e->getMessage()], 422);
    }
    return;
}

if ($method === 'GET' && preg_match('#^/v1/shares/([0-9A-Za-z]{8,32})/meta$#', $path, $m)) {
    if (!$gate('guess', 60, 60)) {
        return;
    }
    $row = $authorizeHttp($store->getShare($m[1]), $m[1]);
    if ($row === null) {
        return;
    }
    $audit($m[1], 'meta-ok');
    $json($svc->meta($m[1]));
    return;
}

// Capability-based GDPR manifest (id + password when set).
if ($method === 'GET' && preg_match('#^/v1/shares/([0-9A-Za-z]{8,32})/export$#', $path, $m)) {
    if (!$gate('export', 60, 3600)) {
        return;
    }
    $row = $authorizeHttp($store->getShare($m[1]), $m[1]);
    if ($row === null) {
        return;
    }
    $audit($m[1], 'export-ok');
    $json($svc->export($m[1], $sharePassword()));
    return;
}

// Revoke now: link dies (410), bytes deleted immediately, rows swept by purge.
if ($method === 'DELETE' && preg_match('#^/v1/shares/([0-9A-Za-z]{8,32})$#', $path, $m)) {
    if (!$gate('export', 60, 3600)) {
        return;
    }
    $row = $store->getShare($m[1]);
    if ($row === null) {
        $json(['error' => 'unknown share'], 404);
        return;
    }
    // Destructive: password required when set (unless already dead).
    $st = UploadService::authorize($row, $sharePassword());
    if ($st === 'password-required') {
        $json(['error' => 'password required', 'passwordRequired' => true], 401);
        return;
    }
    if ($st === 'password-wrong') {
        $audit($m[1], 'bad-password');
        $json(['error' => 'wrong password'], 401);
        return;
    }
    $driverFor = static fn (string $tier) => Drivers::forTier($tier);
    $store->revokeShare($m[1], time());
    try {
        $driverFor($row['tier'])->delete($row['storage_key']);
    } catch (\Throwable) {
    }
    $audit($m[1], 'revoked');
    $json(['revoked' => true]);
    return;
}

// Stream bytes with HMAC + password + view accounting. L1-real 302s to R2.
if ($method === 'GET' && str_starts_with($path, '/v1/blobs/')) {
    if (!$gate('blob', 120, 60)) {
        return;
    }
    $key = implode('/', array_map('rawurldecode', explode('/', substr($path, strlen('/v1/blobs/')))));
    if (!preg_match('#^u/(l1|l2|l3)/#', $key, $tm) || str_contains($key, '..')) {
        $json(['error' => 'invalid key'], 400);
        return;
    }
    $tier = strtoupper($tm[1]);
    $driver = Drivers::forTier($tier);
    if ($tier === 'L1' && $driver instanceof R2S3Driver && $driver->isReal()) {
        // L1-real bytes live in object storage, never in PHP: use /s/:id.
        $json(['error' => 'L1 bytes are served by object storage; use the short link'], 404);
        return;
    }
    $expires = (int) ($_GET['expires'] ?? 0);
    $sig = (string) ($_GET['sig'] ?? '');
    if (!$driver->verifySignedUrl($key, $expires, $sig)) {
        $json(['error' => 'bad or expired signature'], 403);
        return;
    }
    $shareRow = $store->getShareByKey($key);
    $shareId = $shareRow['id'] ?? null;
    $row = $authorizeHttp($shareRow, $shareId);
    if ($row === null) {
        return;
    }
    if ($tier === 'L1' && $driver instanceof R2S3Driver && $driver->isReal()) {
        header('Location: ' . $driver->signedGetUrl($key, 300), true, 302);
        return;
    }
    $consume = $store->tryConsumeView($shareId);
    if (!$consume['ok']) {
        $audit($shareId, $consume['reason']);
        $json(['error' => $consume['reason'] === 'exhausted' ? 'view limit reached' : 'share ' . $consume['reason']],
            $consume['reason'] === 'not-found' ? 404 : 410);
        return;
    }
    try {
        $size = $driver->size($key);
    } catch (\RuntimeException) {
        $audit($shareId, 'blob-gone');
        $json(['error' => 'blob gone (expired/purged?)'], 410);
        return;
    }
    $mime = $shareRow['mime'] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment');
    header('Accept-Ranges: bytes');
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    $start = 0;
    $end = $size - 1;
    if ($rangeHeader !== '') {
        $range = HttpRange::parse($rangeHeader, $size);
        if ($range === null) {
            $audit($shareId, 'bad-range');
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            return;
        }
        $start = $range['start'];
        $end = $range['end'];
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $chunk = 1048576;
    for ($off = $start; $off <= $end; $off += $chunk) {
        echo $driver->readRange($key, $off, (int) min($chunk, $end - $off + 1));
        flush();
    }
    $audit($shareId, 'blob-ok');
    if ($consume['spent']) {
        // Burn / last view: bytes + rows die with this read.
        $svc->deleteShareBlob($shareId, static fn (string $t) => Drivers::forTier($t));
    }
    return;
}

// Short link: 302 to the tier-appropriate signed URL.
if ($method === 'GET' && preg_match('#^/s/([0-9A-Za-z]{8,32})$#', $path, $m)) {
    if (!$gate('guess', 60, 60)) {
        return;
    }
    $row = $authorizeHttp($store->getShare($m[1]), $m[1]);
    if ($row === null) {
        return;
    }
    $driver = Drivers::forTier($row['tier']);
    $isL1Real = $row['tier'] === 'L1' && $driver instanceof R2S3Driver && $driver->isReal();
    if ($isL1Real) {
        // Bytes never touch PHP here — consume the view at redirect time.
        // Residual risk (documented): the issued SigV4 URL stays valid for its
        // short TTL, so L2/L3 proxy is recommended for strict burn semantics.
        $consume = $store->tryConsumeView($m[1]);
        if (!$consume['ok']) {
            $audit($m[1], $consume['reason']);
            $json(['error' => 'view limit reached'], 410);
            return;
        }
        if ($consume['spent']) {
            // Single-use L1-real link: revoke after issuing (blob stays till purge).
            $store->revokeShare($m[1], time());
        }
    }
    $audit($m[1], 'redirect-ok');
    // Short TTL for L1-real (presigned URLs can't be un-issued): 5 min.
    $ttl = $isL1Real ? 300 : 900;
    header('Location: ' . $driver->signedGetUrl($row['storage_key'], $ttl), true, 302);
    return;
}

// Authenticated asset list.
if ($method === 'GET' && $path === '/v1/me/assets') {
    $owner = $me();
    if ($owner === null) {
        $json(['error' => 'unauthorized'], 401);
        return;
    }
    $assets = $store->assetsFor($owner['id']);
    foreach ($assets as &$a) {
        $a['expiresAt'] = $a['expires_at'] !== null ? gmdate('c', (int) $a['expires_at']) : null;
        unset($a['expires_at']);
    }
    $json(['assets' => $assets, 'usage' => $store->userUsage($owner['id']), 'quota' => (int) $owner['quota_bytes']]);
    return;
}

// Per-user GDPR export: JSON manifest or real ZIP of owned bytes.
if ($method === 'GET' && $path === '/v1/me/export') {
    $owner = $me();
    if ($owner === null) {
        $json(['error' => 'unauthorized'], 401);
        return;
    }
    if (($_GET['format'] ?? 'json') === 'zip') {
        $zipPath = sys_get_temp_dir() . '/pwf-export-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $manifest = $svc->exportUser($owner['id']);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        foreach ($store->assetsFor($owner['id']) as $a) {
            $tmp = sys_get_temp_dir() . '/pwf-exp-' . bin2hex(random_bytes(8)) . '.bin';
            $fh = fopen($tmp, 'wb');
            $driver = Drivers::forTier($a['tier']);
            for ($off = 0; $off < $a['size']; $off += 1048576) {
                fwrite($fh, $driver->readRange($a['storage_key'], $off, min(1048576, $a['size'] - $off)));
            }
            fclose($fh);
            $zip->addFile($tmp, 'files/' . $a['id'] . '-' . Shares::safeBasename($a['filename']));
            // ZipArchive reads at close(); track temps via manifest files list.
            $tmps[] = $tmp;
        }
        $zip->close();
        foreach ($tmps ?? [] as $t) {
            unlink($t);
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="privatewf-export.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
        return;
    }
    $json($svc->exportUser($owner['id']) + ['usage' => $store->userUsage($owner['id'])]);
    return;
}

// Account erasure: password-confirmed cascade (blobs + rows + tokens + user).
if ($method === 'DELETE' && $path === '/v1/me') {
    $owner = $me();
    if ($owner === null) {
        $json(['error' => 'unauthorized'], 401);
        return;
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $user = $store->findUserByEmail($owner['email']);
    if (!password_verify((string) ($body['password'] ?? ''), $user['pw_hash'] ?? '')) {
        $json(['error' => 'invalid credentials'], 401);
        return;
    }
    $n = $svc->deleteUserAccount($owner['id'], static fn (string $t) => Drivers::forTier($t));
    $json(['deletedShares' => $n, 'account' => 'deleted']);
    return;
}

// tus 1.0.0 resumable subset (L2/L3 + staged L1). Creation advertises Location.
if ($method === 'OPTIONS') {
    header('Tus-Version: 1.0.0');
    header('Tus-Extension: creation,termination');
    header('Tus-Max-Size: ' . Shares::MAX_FILE_BYTES);
    http_response_code(204);
    return;
}

$tusVersion = static function () use ($json): bool {
    if (($_SERVER['HTTP_TUS_RESUMABLE'] ?? '') !== '1.0.0') {
        $json(['error' => 'Tus-Resumable: 1.0.0 required'], 412);
        return false;
    }
    return true;
};

if ($method === 'POST' && $path === '/v1/uploads/tus') {
    if (!$tusVersion() || !$gate('init', 30, 3600)) {
        return;
    }
    $tier = in_array($_GET['tier'] ?? 'L2', ['L1', 'L2', 'L3'], true) ? $_GET['tier'] : 'L2';
    $length = (int) ($_SERVER['HTTP_UPLOAD_LENGTH'] ?? 0);
    try {
        $owner = $me();
        $r = $svc->tusCreate($tier, $length, (string) ($_SERVER['HTTP_UPLOAD_METADATA'] ?? ''),
            $owner['id'] ?? null, $owner !== null ? (int) $owner['quota_bytes'] : null);
    } catch (\InvalidArgumentException $e) {
        $json(['error' => $e->getMessage()], 422);
        return;
    } catch (\RuntimeException $e) {
        $json(['error' => $e->getMessage()], 413);
        return;
    }
    header('Tus-Resumable: 1.0.0');
    header('Location: ' . Drivers::appUrl() . '/v1/uploads/tus/' . $r['uploadId']);
    http_response_code(201);
    return;
}

if (preg_match('#^/v1/uploads/tus/(up_[0-9A-Za-z]{8,32})$#', $path, $m)) {
    if (!$tusVersion()) {
        return;
    }
    header('Tus-Resumable: 1.0.0');
    if ($method === 'HEAD') {
        try {
            $cur = $svc->tusOffset($m[1]);
        } catch (\RuntimeException) {
            $json(['error' => 'unknown upload'], 404);
            return;
        }
        header('Upload-Offset: ' . $cur['offset']);
        header('Upload-Length: ' . $cur['length']);
        header('Cache-Control: no-store');
        return;
    }
    if ($method === 'PATCH') {
        if (!$gate('append', 600, 3600)) {
            return;
        }
        if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/offset+octet-stream') {
            $json(['error' => 'Content-Type must be application/offset+octet-stream'], 415);
            return;
        }
        try {
            $r = $svc->tusAppend($m[1], (int) ($_SERVER['HTTP_UPLOAD_OFFSET'] ?? -1),
                file_get_contents('php://input') ?: '');
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'mismatch') ? 409 : (str_contains($e->getMessage(), 'exceeds') ? 413 : 404);
            $json(['error' => $e->getMessage()], $code);
            return;
        }
        header('Upload-Offset: ' . $r['received']);
        http_response_code(204);
        return;
    }
    if ($method === 'DELETE') {
        $svc->tusTerminate($m[1]);
        http_response_code(204);
        return;
    }
}

$json(['error' => 'not found', 'see' => 'GET /health, GET /v1/tiers'], 404);
