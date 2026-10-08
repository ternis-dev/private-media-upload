<?php

declare(strict_types=1);

namespace PrivateWf\Storage\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Storage\SftpVaultDriver;

/**
 * Live SFTP roundtrip (atmoz/sftp as Hetzner-box stand-in). Skipped unless:
 *   TEST_SFTP_HOST=localhost TEST_SFTP_PORT=2222 TEST_SFTP_USER=vault TEST_SFTP_PASS=... TEST_SFTP_ROOT=/vault
 * CI runs it as a docker step; local needs an SFTP server (compose sftp service).
 */
final class SftpLiveTest extends TestCase
{
    public function test_put_get_range_delete_roundtrip(): void
    {
        $host = getenv('TEST_SFTP_HOST') ?: '';
        if ($host === '') {
            $this->markTestSkipped('no live SFTP (set TEST_SFTP_HOST)');
        }
        $d = new SftpVaultDriver(
            emulationRoot: sys_get_temp_dir() . '/pwf-unused',
            host: $host,
            port: (int) (getenv('TEST_SFTP_PORT') ?: 22),
            user: getenv('TEST_SFTP_USER') ?: 'vault',
            password: getenv('TEST_SFTP_PASS') ?: '',
            root: getenv('TEST_SFTP_ROOT') ?: '/vault',
        );
        $this->assertTrue($d->isReal());

        $key = 'u/integration/' . bin2hex(random_bytes(6)) . '.bin';
        $d->put($key, 'live-sftp-bytes-0123456789');
        try {
            $this->assertTrue($d->exists($key));
            $this->assertSame(25, $d->size($key));
            $this->assertSame('live-sftp-bytes-0123456789', $d->get($key));
            $this->assertSame('sftp-', $d->readRange($key, 5, 5));

            $src = tempnam(sys_get_temp_dir(), 'pwf-sftp-');
            file_put_contents($src, 'via-putfile');
            $d->putFile($key, $src);
            unlink($src);
            $this->assertSame('via-putfile', $d->get($key));
        } finally {
            $d->delete($key);
        }
        $this->assertFalse($d->exists($key));
    }
}
