<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

final class PurgeCommand extends Command
{
    protected $signature = 'pwf:purge';
    protected $description = 'Delete blobs + rows for expired/revoked shares (Löschkonzept <24h)';

    public function handle(Store $store): int
    {
        $svc = new UploadService($store, Drivers::webUrl());
        $n = $svc->purgeExpired(static fn (string $tier) => Drivers::forTier($tier));
        $this->line(json_encode(['purged' => $n]));
        return self::SUCCESS;
    }
}
