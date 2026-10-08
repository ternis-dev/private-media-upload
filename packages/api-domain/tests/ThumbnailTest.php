<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;
use PrivateWf\Api\Thumbnailer;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;

/** M4: thumbnails for plaintext images/video; never for ciphertext. */
final class ThumbnailTest extends TestCase
{
    private Store $store;
    private UploadService $svc;
    private LocalSovereignDriver $driver;
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/pwf-thumb-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        $this->store = Store::memory($this->tmp . '/var');
        $this->svc = new UploadService($this->store, 'http://localhost:3000');
        $this->driver = new LocalSovereignDriver($this->tmp . '/l3');
    }

    private function redPng(int $w = 800, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        unset($img);
        return $data;
    }

    public function test_image_upload_gets_jpeg_thumb(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('gd missing');
        }
        $png = $this->redPng();
        $r = $this->svc->reserve('L3', 'photo.png', strlen($png), 'image/png');
        $this->svc->append($r['uploadId'], $png);
        $done = $this->svc->complete($r['uploadId'], $this->driver);

        $meta = $this->svc->meta($done['shareId']);
        $this->assertTrue($meta['hasThumbnail']);
        $key = $this->store->getShare($done['shareId'])['storage_key'];
        $thumb = $this->driver->get($key . '.thumb.jpg');
        $this->assertSame("\xFF\xD8", substr($thumb, 0, 2), 'thumbnail is JPEG');
        [$w, $h] = getimagesizefromstring($thumb);
        $this->assertLessThanOrEqual(512, max($w, $h));

        // Purge removes the thumbnail with the bytes.
        $this->assertTrue($this->driver->exists($key . '.thumb.jpg'));
        $this->assertTrue($this->svc->deleteShareBlob($done['shareId'], fn () => $this->driver));
        $this->assertFalse($this->driver->exists($key . '.thumb.jpg'));
    }

    public function test_video_thumb_when_ffmpeg_present(): void
    {
        $hasFfmpeg = trim(shell_exec('command -v ffmpeg 2>/dev/null') ?? '') !== '';
        if (!$hasFfmpeg || !extension_loaded('gd')) {
            $this->markTestSkipped('ffmpeg or gd missing');
        }
        $mp4 = sys_get_temp_dir() . '/pwf-src-' . bin2hex(random_bytes(4)) . '.mp4';
        exec(sprintf('%s -hide_banner -loglevel error -y -f lavfi -i color=c=blue:s=320x240:d=2 -pix_fmt yuv420p %s 2>/dev/null',
            'ffmpeg', escapeshellarg($mp4)), $ignored, $code);
        if ($code !== 0 || !is_file($mp4)) {
            $this->markTestSkipped('cannot synthesize test video');
        }
        try {
            $bytes = file_get_contents($mp4);
            $r = $this->svc->reserve('L3', 'clip.mp4', strlen($bytes), 'video/mp4');
            $this->svc->append($r['uploadId'], $bytes);
            $done = $this->svc->complete($r['uploadId'], $this->driver);
            $this->assertTrue($this->svc->meta($done['shareId'])['hasThumbnail']);
        } finally {
            unlink($mp4);
        }
    }

    public function test_e2ee_never_thumbnailled(): void
    {
        $cipher = random_bytes(256);
        $r = $this->svc->reserve('L3', 'c.pwf1', strlen($cipher), 'application/octet-stream');
        $this->svc->append($r['uploadId'], $cipher);
        $done = $this->svc->complete($r['uploadId'], $this->driver, ['e2ee' => true]);
        $this->assertFalse($this->svc->meta($done['shareId'])['hasThumbnail']);
    }

    public function test_oversize_source_skipped_fast(): void
    {
        $sparse = tempnam(sys_get_temp_dir(), 'pwf-sparse-');
        $fh = fopen($sparse, 'wb');
        ftruncate($fh, Thumbnailer::MAX_SOURCE_BYTES + 1); // sparse: instant, no 50 MB write
        fclose($fh);
        try {
            $this->assertNull(Thumbnailer::make($sparse, 'image/png', Thumbnailer::MAX_SOURCE_BYTES + 1));
        } finally {
            unlink($sparse);
        }
    }

    public function test_non_visual_mime_skipped(): void
    {
        $this->assertNull(Thumbnailer::make(__FILE__, 'text/plain', 100));
    }
}
