<?php

declare(strict_types=1);

// M0 lean router (no framework). Laravel migration in M1 per ADR-0001.
// Run: php -S localhost:8000 -t apps/api/public  (or composer serve in apps/api)

require __DIR__ . '/../vendor/autoload.php';

use PrivateWf\Api\Shares;

header('X-Robots-Tag: noindex, nofollow');
header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

$json = static function (mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
};

if ($method === 'GET' && $path === '/health') {
    $json(['ok' => true, 'service' => 'private-wf-api', 'm' => 'M0']);
    return;
}

if ($method === 'GET' && $path === '/v1/tiers') {
    $json(['tiers' => [
        ['id' => 'L1', 'name' => 'Edge Object Storage', 'residency' => 'global-edge', 'badge' => 'Global edge 🌍', 'backend' => 'Cloudflare R2 (S3-compatible)'],
        ['id' => 'L2', 'name' => 'Provider Vault DE', 'residency' => 'DE-only', 'badge' => 'DE-only 🇩🇪', 'backend' => 'SFTP vault (single location)'],
        ['id' => 'L3', 'name' => 'Sovereign Node', 'residency' => 'DE-only', 'badge' => 'DE-only 🇩🇪 · sovereign', 'backend' => 'own server (local disk)'],
    ]]);
    return;
}

if ($method === 'POST' && $path === '/v1/uploads/init') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    [$ok, $err] = Shares::validateInit($body);
    if (!$ok) {
        $json(['error' => $err], 422);
        return;
    }
    $tier = $body['tier'];
    $shareId = Shares::newId();
    $json([
        'uploadId' => 'up_' . Shares::newId(16),
        'shareId' => $shareId,
        'shareUrl' => 'http://localhost:3000/s/' . $shareId,
        'tier' => $tier,
        'expiresAt' => Shares::defaultExpiry($tier),
        // M0: direct target placeholder. M1: L1 presigned multipart URLs, L2/L3 tus endpoint.
        'target' => $tier === 'L1' ? 'PUT {presigned-r2-url} (M1)' : 'POST /v1/uploads/chunked (tus, M1)',
    ], 201);
    return;
}

if ($method === 'GET' && preg_match('#^/v1/shares/([0-9A-Za-z]{8,32})/meta$#', $path, $m)) {
    if (!Shares::validId($m[1])) {
        $json(['error' => 'invalid id'], 400);
        return;
    }
    $json(['id' => $m[1], 'status' => 'expired-or-unknown (M0 stub: persistence lands M1)', 'tier' => null]);
    return;
}

$json(['error' => 'not found', 'see' => 'GET /health, GET /v1/tiers'], 404);
