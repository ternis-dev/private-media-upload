<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\ClamAv;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;

/** M2a: password / max-views / burn / revoke / export at service level. */
final class ShareSecurityTest extends TestCase
{
    private string $tmp;
    private Store $store;
    private UploadService $svc;
    private LocalSovereignDriver $driver;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pwf-sec-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->store = Store::memory($this->tmp . '/var');
        $this->svc = new UploadService($this->store, 'http://localhost:3000');
        $this->driver = new LocalSovereignDriver($this->tmp . '/l3');
    }

    private function makeShare(array $opts = []): array
    {
        $r = $this->svc->reserve('L3', 'secret.bin', 5, 'application/octet-stream');
        $this->svc->append($r['uploadId'], '12345');
        return $this->svc->complete($r['uploadId'], $this->driver, $opts);
    }

    public function test_password_gate(): void
    {
        $done = $this->makeShare(['password' => 'correct-horse-123']);
        $row = $this->store->getShare($done['shareId']);
        $this->assertNotSame('correct-horse-123', $row['password_hash']);
        $this->assertTrue(password_verify('correct-horse-123', $row['password_hash']));

        $this->assertSame('password-required', UploadService::authorize($row, null));
        $this->assertSame('password-required', UploadService::authorize($row, ''));
        $this->assertSame('password-wrong', UploadService::authorize($row, 'nope'));
        $this->assertSame('ok', UploadService::authorize($row, 'correct-horse-123'));
        $this->assertTrue($this->svc->meta($done['shareId'])['hasPassword']);
    }

    public function test_open_share_needs_no_password(): void
    {
        $done = $this->makeShare();
        $this->assertSame('ok', UploadService::authorize($this->store->getShare($done['shareId']), null));
    }

    public function test_short_password_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeShare(['password' => 'short']);
    }

    public function test_max_views_exhausts_then_finalizes(): void
    {
        $done = $this->makeShare(['maxViews' => 2]);
        $id = $done['shareId'];
        $this->assertSame(['ok' => true, 'spent' => false], $this->store->tryConsumeView($id));
        $this->assertSame(['ok' => true, 'spent' => true], $this->store->tryConsumeView($id));
        $this->assertSame('exhausted', UploadService::authorize($this->store->getShare($id), null));
        $this->assertSame(['ok' => false, 'reason' => 'exhausted'], $this->store->tryConsumeView($id));

        // Finalize like the router does after serving the last view.
        $key = $this->store->getShare($id)['storage_key'];
        $this->assertTrue($this->driver->exists($key));
        $this->assertTrue($this->svc->deleteShareBlob($id, fn () => $this->driver));
        $this->assertFalse($this->driver->exists($key));
        $this->assertNull($this->svc->meta($id));
    }

    public function test_burn_forces_single_view(): void
    {
        [$hash, $maxViews, $burn] = UploadService::validateShareOptions(['burn' => true]);
        $this->assertSame(1, $burn);
        $this->assertSame(1, $maxViews);
        $this->assertNull($hash);

        $done = $this->makeShare(['burn' => true]);
        $id = $done['shareId'];
        $this->assertTrue($this->svc->meta($id)['burn']);
        $this->assertSame(['ok' => true, 'spent' => true], $this->store->tryConsumeView($id));
    }

    public function test_revoke_kills_link_rows_swept_by_purge(): void
    {
        $done = $this->makeShare();
        $id = $done['shareId'];
        $key = $this->store->getShare($id)['storage_key'];
        $row = $this->store->revokeShare($id, time());
        $this->assertNotNull($row['revoked_at']);
        $this->assertSame('revoked', UploadService::authorize($this->store->getShare($id), null));

        // Router deletes bytes immediately; purge sweeps rows.
        $this->driver->delete($key);
        $this->assertSame(1, $this->svc->purgeExpired(fn () => $this->driver));
        $this->assertNull($this->svc->meta($id));
    }

    public function test_export_manifest(): void
    {
        $done = $this->makeShare(['password' => 'export-pw-123']);
        $this->store->logAccess($done['shareId'], 'iph', 'uah', 'meta-ok', 123);
        $this->assertNull($this->svc->export($done['shareId'], 'wrong'));
        $m = $this->svc->export($done['shareId'], 'export-pw-123');
        $this->assertSame($done['shareId'], $m['share']['id']);
        $this->assertArrayNotHasKey('password_hash', $m['share']);
        $this->assertSame('secret.bin', $m['asset']['filename']);
        $this->assertCount(1, $m['accessLog']);
        $this->assertSame('meta-ok', $m['accessLog'][0]['result']);
    }

    public function test_scanner_rejects_malware(): void
    {
        $svc = new UploadService($this->store, 'http://localhost:3000',
            static fn (string $p): ?string => str_contains(file_get_contents($p), 'EICAR') ? 'Eicar-Test' : null);
        $r = $svc->reserve('L3', 'evil.bin', 5, 'application/octet-stream');
        $svc->append($r['uploadId'], 'EICAR');
        try {
            $svc->complete($r['uploadId'], $this->driver);
            $this->fail('expected malware rejection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('malware', $e->getMessage());
        }
        $this->assertFalse(is_file($this->store->stagingPath($r['uploadId'])));
    }

    public function test_scanner_passes_clean(): void
    {
        $svc = new UploadService($this->store, 'http://localhost:3000', static fn (): ?string => null);
        $r = $svc->reserve('L3', 'clean.bin', 6, 'application/octet-stream');
        $svc->append($r['uploadId'], 'clean!');
        $done = $svc->complete($r['uploadId'], $this->driver);
        $this->assertSame('ok', UploadService::authorize($this->store->getShare($done['shareId']), null));
    }
}

