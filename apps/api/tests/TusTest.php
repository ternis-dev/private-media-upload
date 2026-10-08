<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;

/** M2b: tus 1.0.0 creation/offset/patch/terminate subset. */
final class TusTest extends TestCase
{
    private Store $store;
    private UploadService $svc;
    private LocalSovereignDriver $driver;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pwf-tus-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        $this->store = Store::memory($tmp . '/var');
        $this->svc = new UploadService($this->store, 'http://localhost:3000');
        $this->driver = new LocalSovereignDriver($tmp . '/l3');
    }

    private function meta(string $filename, string $mime = 'video/mp4'): string
    {
        return 'filename ' . base64_encode($filename) . ',filetype ' . base64_encode($mime);
    }

    public function test_create_head_patch_complete(): void
    {
        $r = $this->svc->tusCreate('L3', 10, $this->meta('clip.mp4'), null, null);
        $this->assertSame(10, $r['expected']);

        $cur = $this->svc->tusOffset($r['uploadId']);
        $this->assertSame(['offset' => 0, 'length' => 10], $cur);

        $p = $this->svc->tusAppend($r['uploadId'], 0, '12345');
        $this->assertSame(5, $p['received']);
        $p = $this->svc->tusAppend($r['uploadId'], 5, '67890');
        $this->assertSame(10, $p['received']);

        $done = $this->svc->complete($r['uploadId'], $this->driver);
        $this->assertSame('video/mp4', $done['mime']);
        $this->assertSame('clip.mp4', $this->store->getShare($done['shareId'])['filename']);
    }

    public function test_offset_mismatch_rejected(): void
    {
        $r = $this->svc->tusCreate('L3', 10, $this->meta('a.bin', 'application/octet-stream'), null, null);
        $this->svc->tusAppend($r['uploadId'], 0, '12345');
        try {
            $this->svc->tusAppend($r['uploadId'], 0, 'xxxxx');
            $this->fail('expected offset mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('offset mismatch', $e->getMessage());
        }
        // Correct offset resumes cleanly (the resumable point of tus).
        $this->svc->tusAppend($r['uploadId'], 5, '67890');
        $this->svc->complete($r['uploadId'], $this->driver);
    }

    public function test_create_requires_filename_and_length(): void
    {
        try {
            $this->svc->tusCreate('L3', 10, 'filetype ' . base64_encode('video/mp4'), null, null);
            $this->fail('expected filename requirement');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('filename', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->tusCreate('L3', 0, $this->meta('a.bin'), null, null);
    }

    public function test_terminate_drops_staging(): void
    {
        $r = $this->svc->tusCreate('L3', 10, $this->meta('a.bin', 'application/octet-stream'), null, null);
        $this->svc->tusAppend($r['uploadId'], 0, '12345');
        $staging = $this->store->stagingPath($r['uploadId']);
        $this->assertTrue(is_file($staging));
        $this->svc->tusTerminate($r['uploadId']);
        $this->assertFalse(is_file($staging));
        $this->assertNull($this->store->getUpload($r['uploadId']));
    }

    public function test_metadata_parser(): void
    {
        $m = UploadService::parseTusMetadata('filename Y2xpcC5tcDQ=,filetype dmlkZW8vbXA0');
        $this->assertSame('Y2xpcC5tcDQ=', $m['filename']);
        $this->assertSame([], UploadService::parseTusMetadata(''));
        $this->assertSame([], UploadService::parseTusMetadata('9bad value'));
    }
}
