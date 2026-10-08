<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;
use PrivateWf\Storage\R2S3Driver;
use PrivateWf\Storage\SftpVaultDriver;

/**
 * M1 DoD: reserve → chunked append → complete → meta → purge → bytes gone,
 * for L3 (sovereign), L2 (vault) and L1 (emulated R2, incl. direct path).
 */
final class UploadFlowTest extends TestCase
{
    private string $tmp;
    private Store $store;
    private UploadService $svc;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pwf-flow-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->store = Store::memory($this->tmp . '/var');
        $this->svc = new UploadService($this->store, 'http://localhost:3000');
    }

    private function driverFor(string $tier): object
    {
        return match ($tier) {
            'L1' => new R2S3Driver($this->tmp . '/l1'),
            'L2' => new SftpVaultDriver($this->tmp . '/l2'),
            'L3' => new LocalSovereignDriver($this->tmp . '/l3'),
        };
    }

    public static function tiers(): array
    {
        return ['L1 staged' => ['L1'], 'L2 vault' => ['L2'], 'L3 sovereign' => ['L3']];
    }

    /** @dataProvider tiers */
    #[\PHPUnit\Framework\Attributes\DataProvider('tiers')]
    public function test_chunked_flow_then_purge_deletes_bytes(string $tier): void
    {
        $d = $this->driverFor($tier);
        $payload = str_repeat('tier-' . $tier . '-0123456789', 1000); // ~16 KiB
        $r = $this->svc->reserve($tier, 'clip.mp4', strlen($payload), 'video/mp4');
        $this->assertStringStartsWith('up_', $r['uploadId']);

        // 3 chunks.
        foreach (str_split($payload, (int) ceil(strlen($payload) / 3)) as $chunk) {
            $st = $this->svc->append($r['uploadId'], $chunk);
        }
        $this->assertSame(strlen($payload), $st['received']);

        $done = $this->svc->complete($r['uploadId'], $d);
        $this->assertStringStartsWith('http://localhost:3000/s/', $done['shareUrl']);
        $this->assertSame(hash('sha256', $payload), $done['sha256']);

        $meta = $this->svc->meta($done['shareId']);
        $this->assertSame($tier, $meta['tier']);
        $this->assertSame(strlen($payload), $meta['size']);
        $this->assertFalse($meta['expired']);
        $this->assertSame($payload, $d->get($this->store->getShare($done['shareId'])['storage_key']));

        // Force expiry → purge → bytes + metadata gone.
        $this->store->setShareExpiry($done['shareId'], time() - 1);
        $this->assertTrue($this->svc->meta($done['shareId'])['expired']);
        $n = $this->svc->purgeExpired(fn (string $t) => $this->driverFor($t));
        $this->assertSame(1, $n);
        $this->assertNull($this->svc->meta($done['shareId']));
        $this->assertNull($this->store->getShare($done['shareId']));
    }

    public function test_l1_direct_complete_verifies_size(): void
    {
        /** @var R2S3Driver $d */
        $d = $this->driverFor('L1');
        $d->put('u/l1/direct.bin', 'direct-bytes');
        $done = $this->svc->completeL1('u/l1/direct.bin', 'direct.bin', 12, 'application/octet-stream', $d);
        $this->assertSame('L1', $done['tier']);

        $this->expectException(\RuntimeException::class);
        $this->svc->completeL1('u/l1/direct.bin', 'direct.bin', 999, 'application/octet-stream', $d);
    }

    public function test_l1_direct_rejects_missing_object(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->completeL1('u/l1/nope.bin', 'nope.bin', 5, 'application/octet-stream', $this->driverFor('L1'));
    }

    public function test_append_overflow_rejected(): void
    {
        $r = $this->svc->reserve('L3', 'a.bin', 10, 'application/octet-stream');
        $this->expectException(\RuntimeException::class);
        $this->svc->append($r['uploadId'], str_repeat('x', 11));
    }

    public function test_complete_incomplete_rejected(): void
    {
        $r = $this->svc->reserve('L3', 'a.bin', 10, 'application/octet-stream');
        $this->svc->append($r['uploadId'], 'short');
        $this->expectException(\RuntimeException::class);
        $this->svc->complete($r['uploadId'], $this->driverFor('L3'));
    }

    public function test_reserve_rejects_bad_tier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->reserve('L9', 'a.bin', 10, 'application/octet-stream');
    }

    public function test_drivers_factory_tiers(): void
    {
        $this->assertSame('L1', Drivers::forTier('L1')->tier()->value);
        $this->assertSame('L2', Drivers::forTier('L2')->tier()->value);
        $this->assertSame('L3', Drivers::forTier('L3')->tier()->value);
    }
}
