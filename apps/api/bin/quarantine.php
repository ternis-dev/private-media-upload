#!/usr/bin/env php
<?php

declare(strict_types=1);

// Quarantine worker (closes R1): scans assets that skipped inline AV —
// notably browser-direct-to-R2 uploads. Streams each object to a temp file,
// scans via ClamAV, deletes shares+bytes on hit.
// Run: php bin/quarantine.php   (cron: hourly; requires CLAMAV_HOST)

require __DIR__ . '/../vendor/autoload.php';

use PrivateWf\Api\ClamAv;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

$clam = ClamAv::fromEnv();
if (!$clam->enabled()) {
    echo json_encode(['skipped' => 'CLAMAV_HOST unset']) . PHP_EOL;
    exit(0);
}
$store = Store::open(Drivers::dbPath(), Drivers::varDir());
$svc = new UploadService($store, Drivers::webUrl());
$res = $svc->quarantineUnscanned(
    static fn (string $tier) => Drivers::forTier($tier),
    $clam->scanFile(...),
    sys_get_temp_dir());
echo json_encode($res) . PHP_EOL;
