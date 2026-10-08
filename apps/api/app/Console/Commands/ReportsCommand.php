<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PrivateWf\Api\Store;

final class ReportsCommand extends Command
{
    protected $signature = 'pwf:reports {--status=open : open|actioned|dismissed|all} {--action= : mark report: <id>:<actioned|dismissed>}';
    protected $description = 'Review abuse reports (take action via share revoke, then mark)';

    public function handle(Store $store): int
    {
        $action = (string) $this->option('action');
        if ($action !== '') {
            [$id, $status] = array_pad(explode(':', $action, 2), 2, '');
            try {
                $store->setReportStatus($id, $status);
                $this->line("marked {$id} → {$status}");
                return self::SUCCESS;
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
        }
        $status = (string) $this->option('status');
        $rows = $status === 'all'
            ? array_merge($store->listReports('open'), $store->listReports('actioned'), $store->listReports('dismissed'))
            : $store->listReports($status);
        foreach ($rows as $r) {
            $this->line("{$r['id']} [{$r['status']}] share={$r['share_id']} reason={$r['reason']} contact=" . ($r['contact'] ?? '-'));
        }
        $this->line('count: ' . count($rows));
        return self::SUCCESS;
    }
}
