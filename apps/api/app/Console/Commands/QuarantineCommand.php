<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PrivateWf\Api\ClamAv;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

final class QuarantineCommand extends Command
{
    protected $signature = 'pwf:quarantine';
    protected $description = 'Scan unscanned assets (closes R1); quarantine on hit';

    public function handle(Store $store, ClamAv $clam): int
    {
        if (!$clam->enabled()) {
            $this->line(json_encode(['skipped' => 'CLAMAV_HOST unset']));
            return self::SUCCESS;
        }
        $svc = new UploadService($store, Drivers::webUrl());
        $res = $svc->quarantineUnscanned(
            static fn (string $tier) => Drivers::forTier($tier),
            $clam->scanFile(...),
            sys_get_temp_dir());
        $this->line(json_encode($res));
        return self::SUCCESS;
    }
}
