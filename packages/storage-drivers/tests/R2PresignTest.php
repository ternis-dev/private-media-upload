<?php

declare(strict_types=1);

namespace PrivateWf\Storage\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Storage\R2S3Driver;

/**
 * SigV4 presign is pure local HMAC — verifiable without any network.
 * Proves the L1 browser-direct flow produces real presigned URLs;
 * live PUT/GET roundtrip is covered by R2MinioIntegrationTest (CI).
 */
final class R2PresignTest extends TestCase
{
    private function driver(): R2S3Driver
    {
        return new R2S3Driver(
            emulationRoot: sys_get_temp_dir() . '/pwf-unused',
            bucket: 'privatewf-l1',
            endpoint: 'https://r2.private.wf',
            key: 'dummy-key',
            secret: 'dummy-secret',
        );
    }

    public function test_real_mode_detected(): void
    {
        $this->assertTrue($this->driver()->isReal());
        $this->assertFalse((new R2S3Driver(sys_get_temp_dir() . '/pwf-emu'))->isReal());
    }

    public function test_presigned_put_url_is_sigv4(): void
    {
        $url = $this->driver()->presignedPutUrl('u/a/b.mp4', 'video/mp4', 3600);
        $this->assertStringStartsWith('https://r2.private.wf/privatewf-l1/u/a/b.mp4?', $url);
        foreach (['X-Amz-Algorithm=AWS4-HMAC-SHA256', 'X-Amz-Credential=', 'X-Amz-Signature='] as $needle) {
            $this->assertStringContainsString($needle, $url);
        }
    }

    public function test_signed_get_url_is_sigv4(): void
    {
        $url = $this->driver()->signedGetUrl('u/a/b.mp4', 900);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function test_emulation_fallback_shapes(): void
    {
        $d = new R2S3Driver(sys_get_temp_dir() . '/pwf-emu-' . bin2hex(random_bytes(4)));
        $this->assertStringContainsString('expires_in=', $d->presignedPutUrl('u/x', 'video/mp4'));
        $url = $d->signedGetUrl('u/x');
        $this->assertStringContainsString('/v1/blobs/u%2Fx?', $url);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        $this->assertTrue($d->verifySignedUrl('u/x', (int) $q['expires'], (string) $q['sig']));
        $this->assertSame(sys_get_temp_dir() . '', substr($d->localPath('u/x'), 0, strlen(sys_get_temp_dir())));
    }
}
