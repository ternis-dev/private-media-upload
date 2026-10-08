<?php

declare(strict_types=1);

namespace PrivateWf\Storage\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Storage\R2S3Driver;

/**
 * Live S3 roundtrip (MinIO as R2 stand-in). Skipped unless env set:
 *   TEST_MINIO_ENDPOINT=http://localhost:9000 TEST_MINIO_KEY=minioadmin TEST_MINIO_SECRET=minioadmin
 * CI runs it as a service container; local: `docker run -p 9000:9000 minio/minio server /data`.
 */
final class R2MinioIntegrationTest extends TestCase
{
    public function test_put_get_presign_roundtrip(): void
    {
        $endpoint = getenv('TEST_MINIO_ENDPOINT') ?: '';
        if ($endpoint === '') {
            $this->markTestSkipped('no live S3 (set TEST_MINIO_ENDPOINT)');
        }
        $bucket = 'pwf-test-' . bin2hex(random_bytes(4));
        $d = new R2S3Driver(
            emulationRoot: sys_get_temp_dir() . '/pwf-unused',
            bucket: $bucket,
            endpoint: $endpoint,
            key: getenv('TEST_MINIO_KEY') ?: 'minioadmin',
            secret: getenv('TEST_MINIO_SECRET') ?: 'minioadmin',
        );
        $this->assertTrue($d->isReal());
        $d->ensureBucket();

        $key = 'u/integration/' . bin2hex(random_bytes(6)) . '.bin';
        $d->put($key, 'live-minio-bytes');
        $this->assertTrue($d->exists($key));
        $this->assertSame(15, $d->size($key));
        $this->assertSame('live-minio-bytes', $d->get($key));

        $putUrl = $d->presignedPutUrl($key, 'application/octet-stream', 300);
        $this->assertStringContainsString('X-Amz-Signature', $putUrl);
        $getUrl = $d->signedGetUrl($key, 300);
        $this->assertStringContainsString('X-Amz-Signature', $getUrl);

        // Presigned PUT via plain HTTP (proves browser-direct flow works).
        $ch = curl_init($putUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => 'via-presigned-url',
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->assertSame(200, $code, 'presigned PUT failed: ' . $body);
        $this->assertSame('via-presigned-url', $d->get($key));

        $d->delete($key);
        $this->assertFalse($d->exists($key));
    }
}
