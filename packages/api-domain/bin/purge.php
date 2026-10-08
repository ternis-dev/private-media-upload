#!/usr/bin/env php
<?php

declare(strict_types=1);

// Purge expired shares: delete blobs via tier drivers, drop metadata.
// Run: php bin/purge.php   (cron: nightly; Löschkonzept: docs/gdpr/ — M2)

require __DIR__ . '/../vendor/autoload.php';

use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

$store = Store::open(Drivers::dbPath(), Drivers::varDir());
$svc = new UploadService($store, Drivers::webUrl());
$n = $svc->purgeExpired(static fn (string $tier) => Drivers::forTier($tier));
echo json_encode(['purged' => $n]) . PHP_EOL;
