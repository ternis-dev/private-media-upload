<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;

/**
 * M3: server stays blind — ciphertext in, ciphertext stored, e2ee flag out.
 * Key management is entirely client-side (see web/lib/e2ee.ts + format doc).
 */
final class E2eeFlowTest extends TestCase
{
    private Store $store;
    private UploadService $svc;
    private LocalSovereignDriver $driver;
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pwf-e2ee-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->store = Store::memory($this->tmp . '/var');
        $calls = 0;
        $scanner = static function () use (&$calls): ?string {
            $calls++;
            return null;
        };
        $this->svc = new UploadService($this->store, 'http://localhost:3000', $scanner);
        $this->svcScannerCalls = &$calls;
        $this->driver = new LocalSovereignDriver($this->tmp . '/l3');
    }

    private int $svcScannerCalls = 0;

    public function test_e2ee_upload_skips_scan_and_flags_meta(): void
    {
        // Fake "ciphertext": random bytes the server must never interpret.
        $cipher = random_bytes(1024);
        $r = $this->svc->reserve('L3', 'secret.pwf1', strlen($cipher), 'application/octet-stream');
        $this->svc->append($r['uploadId'], $cipher);
        $done = $this->svc->complete($r['uploadId'], $this->driver, ['e2ee' => true]);

        $this->assertSame(0, $this->svcScannerCalls, 'ciphertext must not be scanned');
        $meta = $this->svc->meta($done['shareId']);
        $this->assertTrue($meta['e2ee']);

        $stored = $this->driver->get($this->store->getShare($done['shareId'])['storage_key']);
        $this->assertSame($cipher, $stored, 'server stores opaque bytes verbatim');
    }

    public function test_plaintext_upload_still_scanned_and_unflagged(): void
    {
        $r = $this->svc->reserve('L3', 'plain.bin', 4, 'application/octet-stream');
        $this->svc->append($r['uploadId'], 'data');
        $done = $this->svc->complete($r['uploadId'], $this->driver);
        $this->assertSame(1, $this->svcScannerCalls);
        $this->assertFalse($this->svc->meta($done['shareId'])['e2ee']);
    }

    public function test_quarantine_skips_ciphertext(): void
    {
        $cipher = random_bytes(64);
        $r = $this->svc->reserve('L3', 'c.pwf1', strlen($cipher), 'application/octet-stream');
        $this->svc->append($r['uploadId'], $cipher);
        $done = $this->svc->complete($r['uploadId'], $this->driver, ['e2ee' => true]);

        $res = $this->svc->quarantineUnscanned(fn () => $this->driver, static fn (): ?string => 'X', $this->tmp);
        $this->assertSame(['scanned' => 0, 'quarantined' => 0, 'skipped' => 1], $res);
        // Share untouched by the (fake-positive) scanner.
        $this->assertNotNull($this->svc->meta($done['shareId']));
        $this->assertSame(2, (int) $this->store->getShare($done['shareId'])['scanned']);
    }
}
