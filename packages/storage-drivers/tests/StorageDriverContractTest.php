<?php

declare(strict_types=1);

namespace PrivateWf\Storage\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Storage\LocalSovereignDriver;
use PrivateWf\Storage\R2S3Driver;
use PrivateWf\Storage\SftpVaultDriver;
use PrivateWf\Storage\StorageDriverInterface;
use PrivateWf\Storage\Tier;

/**
 * Contract test: every driver (L1/L2/L3) must behave identically for
 * put/get/exists/delete/signed-url. Backends differ, guarantees don't.
 */
final class StorageDriverContractTest extends TestCase
{
    /** @return array<string, array{StorageDriverInterface}> */
    public static function drivers(): array
    {
        $tmp = sys_get_temp_dir() . '/pwf-' . bin2hex(random_bytes(4));
        return [
            'L1 R2' => [new R2S3Driver($tmp . '/l1')],
            'L2 SFTP vault' => [new SftpVaultDriver($tmp . '/l2')],
            'L3 sovereign' => [new LocalSovereignDriver($tmp . '/l3')],
        ];
    }

    /** @dataProvider drivers */
    #[\PHPUnit\Framework\Attributes\DataProvider('drivers')]
    public function test_tier_matches_placement(StorageDriverInterface $d): void
    {
        $this->assertContains($d->tier(), [Tier::L1, Tier::L2, Tier::L3]);
    }

    /** @dataProvider drivers */
    #[\PHPUnit\Framework\Attributes\DataProvider('drivers')]
    public function test_put_get_delete_roundtrip(StorageDriverInterface $d): void
    {
        $key = 'u/test/' . bin2hex(random_bytes(6)) . '.bin';
        $this->assertFalse($d->exists($key));
        $d->put($key, 'hello-private-wf');
        $this->assertTrue($d->exists($key));
        $this->assertSame('hello-private-wf', $d->get($key));
        // overwrite
        $d->put($key, 'v2');
        $this->assertSame('v2', $d->get($key));
        $d->delete($key);
        $this->assertFalse($d->exists($key));
    }

    /** @dataProvider drivers */
    #[\PHPUnit\Framework\Attributes\DataProvider('drivers')]
    public function test_get_missing_throws(StorageDriverInterface $d): void
    {
        $this->expectException(\RuntimeException::class);
        $d->get('u/missing/' . bin2hex(random_bytes(6)));
    }

    /** @dataProvider drivers */
    #[\PHPUnit\Framework\Attributes\DataProvider('drivers')]
    public function test_signed_url_embeds_expiry(StorageDriverInterface $d): void
    {
        $url = $d->signedGetUrl('u/a/b.bin', 900);
        $this->assertStringContainsString('expires=', $url);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        $this->assertGreaterThan(time() + 800, (int) ($q['expires'] ?? 0));
    }

    public function test_l3_signed_url_verify(): void
    {
        $d = new LocalSovereignDriver(sys_get_temp_dir() . '/pwf-verify-' . bin2hex(random_bytes(4)));
        $url = $d->signedGetUrl('u/x.bin', 900);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        $this->assertTrue($d->verifySignedUrl('u/x.bin', (int) $q['expires'], (string) $q['sig']));
        $this->assertFalse($d->verifySignedUrl('u/x.bin', time() - 10, (string) $q['sig']));
        $this->assertFalse($d->verifySignedUrl('u/y.bin', (int) $q['expires'], (string) $q['sig']));
    }

    public function test_path_traversal_rejected(): void
    {
        $d = new LocalSovereignDriver(sys_get_temp_dir() . '/pwf-trav-' . bin2hex(random_bytes(4)));
        $this->expectException(\InvalidArgumentException::class);
        $d->put('../evil', 'x');
    }
}
