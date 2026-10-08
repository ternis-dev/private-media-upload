<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;

/**
 * pgsql compatibility (prod target). Skipped unless PWF_PGSQL_DSN is set, e.g.
 *   PWF_PGSQL_DSN="pgsql:host=localhost;port=5432;dbname=privatewf_test" PWF_PGSQL_USER=… PWF_PGSQL_PASS=…
 * CI runs it against a postgres service; local dev stays on sqlite.
 */
final class PgsqlCompatTest extends TestCase
{
    public function test_full_flow_on_pgsql(): void
    {
        $dsn = getenv('PWF_PGSQL_DSN') ?: '';
        if ($dsn === '') {
            $this->markTestSkipped('no pgsql (set PWF_PGSQL_DSN)');
        }
        $tmp = sys_get_temp_dir() . '/pwf-pg-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/staging', 0700, true);
        $pdo = new \PDO($dsn, getenv('PWF_PGSQL_USER') ?: '', getenv('PWF_PGSQL_PASS') ?: '');
        $store = Store::fromPdo($pdo, $tmp);

        $svc = new UploadService($store, 'http://localhost:3000');
        $driver = new LocalSovereignDriver($tmp . '/l3');
        $u = $svc->register('pg@example.com', 'account-password-123', 100000);
        $r = $svc->reserve('L3', 'pg.bin', 4, 'application/octet-stream', $u['id'], 100000);
        $svc->append($r['uploadId'], 'data');
        $done = $svc->complete($r['uploadId'], $driver, ['password' => 'pw-12345678', 'maxViews' => 2]);
        $this->assertSame('ok', UploadService::authorize($store->getShare($done['shareId']), 'pw-12345678'));
        $this->assertSame(['ok' => true, 'spent' => false], $store->tryConsumeView($done['shareId']));
        $this->assertTrue($store->rateHit('k', 1, 60, time())['allowed']);
        $this->assertFalse($store->rateHit('k', 1, 60, time())['allowed']);
        $store->logAccess($done['shareId'], 'h', 'u', 'blob-ok', time());
        $this->assertCount(1, $store->accessLogFor($done['shareId']));
        $export = $svc->exportUser($u['id']);
        $this->assertCount(1, $export['assets']);
        $n = $svc->deleteUserAccount($u['id'], fn () => $driver);
        $this->assertSame(1, $n);
        $this->assertNull($store->findUserByEmail('pg@example.com'));

        // Leave no trace for repeat runs.
        $pdo->exec('DROP TABLE IF EXISTS access_log, ratelimits, tokens, shares, assets, uploads, users');
    }
}
