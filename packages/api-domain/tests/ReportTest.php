<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;

/** M4: abuse reports — unauthenticated filing, ops review queue. */
final class ReportTest extends TestCase
{
    private Store $store;
    private UploadService $svc;
    private string $shareId;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pwf-rep-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        $this->store = Store::memory($tmp . '/var');
        $this->svc = new UploadService($this->store, 'http://localhost:3000');
        $driver = new LocalSovereignDriver($tmp . '/l3');
        $r = $this->svc->reserve('L3', 'a.bin', 3, 'application/octet-stream');
        $this->svc->append($r['uploadId'], 'abc');
        $this->shareId = $this->svc->complete($r['uploadId'], $driver)['shareId'];
    }

    public function test_file_and_review_report(): void
    {
        $id = $this->svc->reportShare($this->shareId, 'copyright', 'holder@example.com');
        $this->assertStringStartsWith('rp_', $id);
        $open = $this->store->listReports();
        $this->assertCount(1, $open);
        $this->assertSame('copyright', $open[0]['reason']);
        $this->store->setReportStatus($id, 'actioned');
        $this->assertSame([], $this->store->listReports());
        $this->assertCount(1, $this->store->listReports('actioned'));
    }

    public function test_bad_reason_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->reportShare($this->shareId, 'spam', null);
    }

    public function test_bad_contact_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->reportShare($this->shareId, 'other', 'not-an-email');
    }

    public function test_unknown_share_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->reportShare('00000000', 'other', null);
    }

    public function test_bad_status_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->setReportStatus('rp_x', 'nope');
    }
}
