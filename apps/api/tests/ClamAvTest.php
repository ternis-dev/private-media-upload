<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\ClamAv;

final class ClamAvTest extends TestCase
{
    public function test_parse_ok(): void
    {
        $this->assertNull(ClamAv::parseResponse('stream: OK'));
    }

    public function test_parse_found(): void
    {
        $this->assertSame('Eicar-Test-Signature',
            ClamAv::parseResponse('stream: Eicar-Test-Signature FOUND'));
    }

    public function test_parse_garbage_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        ClamAv::parseResponse('weird');
    }

    public function test_disabled_scanner_skips(): void
    {
        $clam = new ClamAv();
        $this->assertFalse($clam->enabled());
        $this->assertNull($clam->scanFile(__FILE__));
    }
}
