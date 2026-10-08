<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;
use PrivateWf\Storage\R2S3Driver;

/** M2b: quarantine worker closes R1 (direct-to-R2 assets get scanned async). */
final class QuarantineTest extends TestCase
{
    private Store $store;
    private UploadService $svc;
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pwf-q-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->store = Store::memory($this->tmp . '/var');
        $this->svc = new UploadService($this->store, 'http://localhost:3000');
    }

    private function directL1(string $name, string $bytes): array
    {
        $d = new R2S3Driver($this->tmp . '/l1');
        $d->put('u/l1/' . $name, $bytes);
        $done = $this->svc->completeL1('u/l1/' . $name, $name, strlen($bytes), 'application/octet-stream', $d);
        return [$d, $done];
    }

    public function test_clean_direct_asset_marked_scanned(): void
    {
        [$d, $done] = $this->directL1('clean.bin', 'clean-bytes');
        $this->assertSame(0, (int) $this->store->getShare($done['shareId'])['scanned']);

        $res = $this->svc->quarantineUnscanned(fn () => $d, static fn (): ?string => null, $this->tmp);
        $this->assertSame(['scanned' => 1, 'quarantined' => 0], $res);
        $this->assertSame(1, (int) $this->store->getShare($done['shareId'])['scanned']);
        $this->assertNotNull($this->svc->meta($done['shareId'])); // untouched
    }

    public function test_infected_direct_asset_quarantined(): void
    {
        [$d, $done] = $this->directL1('evil.bin', 'EICAR-payload');
        $res = $this->svc->quarantineUnscanned(
            fn () => $d, static fn (string $p): ?string => str_contains(file_get_contents($p), 'EICAR') ? 'Eicar' : null, $this->tmp);
        $this->assertSame(['scanned' => 0, 'quarantined' => 1], $res);
        $this->assertFalse($d->exists('u/l1/evil.bin'));
        $this->assertNull($this->svc->meta($done['shareId']));
    }

    public function test_staged_without_inline_scan_covered_by_worker(): void
    {
        // Scanner disabled at complete() → asset stays unscanned → worker covers it.
        $l3 = new LocalSovereignDriver($this->tmp . '/l3');
        $r = $this->svc->reserve('L3', 'late.bin', 4, 'application/octet-stream');
        $this->svc->append($r['uploadId'], 'late');
        $done = $this->svc->complete($r['uploadId'], $l3);
        $res = $this->svc->quarantineUnscanned(fn () => $l3, static fn (): ?string => null, $this->tmp);
        $this->assertSame(1, $res['scanned']);
        $this->assertNotNull($this->svc->meta($done['shareId']));
    }
}
