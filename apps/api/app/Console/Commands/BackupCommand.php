<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PrivateWf\Api\Store;

/**
 * Consistent sqlite snapshot (VACUUM INTO) + manifest for off-site copies.
 * pgsql prod uses pg_dump (see runbook). Restore procedure is
 * restore-then-purge: expired shares must not resurrect (runbook).
 */
final class BackupCommand extends Command
{
    protected $signature = 'pwf:backup {--dir= : destination (default VAR_DIR/backups)}';
    protected $description = 'Snapshot metadata DB + manifest (erasure-aware, see runbook)';

    public function handle(Store $store): int
    {
        $varDir = $store->varDir;
        $dest = (string) ($this->option('dir') ?: $varDir . '/backups');
        if (!is_dir($dest)) {
            mkdir($dest, 0700, true);
        }
        $stamp = gmdate('Ymd-His');
        $manifest = [
            'at' => gmdate('c'),
            'db' => null,
            'note' => 'bytes live in tier drivers; back those up per runbook (vault sync / R2 replication)',
        ];
        $dbPath = \PrivateWf\Api\Env::get('DB_PATH') ?: $varDir . '/privatewf.sqlite';
        if (str_ends_with($dbPath, '.sqlite') && is_file($dbPath)) {
            $snapshot = "{$dest}/privatewf-{$stamp}.sqlite";
            $pdo = new \PDO('sqlite:' . $dbPath);
            $pdo->exec("VACUUM INTO " . $pdo->quote($snapshot));
            $manifest['db'] = basename($snapshot);
        } else {
            $manifest['db'] = 'skipped (non-sqlite DSN — use pg_dump per runbook)';
        }
        file_put_contents("{$dest}/manifest-{$stamp}.json", json_encode($manifest, JSON_PRETTY_PRINT));
        $this->line(json_encode(['backup' => $dest, 'db' => $manifest['db']]));
        return self::SUCCESS;
    }
}
