<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\HttpRange;

final class HttpRangeTest extends TestCase
{
    public function test_full_open_range(): void
    {
        $this->assertSame(['start' => 0, 'end' => 99], HttpRange::parse('bytes=0-', 100));
    }

    public function test_closed_range_clamped(): void
    {
        $this->assertSame(['start' => 10, 'end' => 99], HttpRange::parse('bytes=10-500', 100));
        $this->assertSame(['start' => 10, 'end' => 20], HttpRange::parse('bytes=10-20', 100));
    }

    public function test_suffix_range(): void
    {
        $this->assertSame(['start' => 90, 'end' => 99], HttpRange::parse('bytes=-10', 100));
    }

    public function test_invalid_rejected(): void
    {
        $this->assertNull(HttpRange::parse('bytes=200-300', 100));
        $this->assertNull(HttpRange::parse('bytes=-', 100));
        $this->assertNull(HttpRange::parse('items=0-10', 100));
        $this->assertNull(HttpRange::parse('bytes=30-10', 100));
    }
}
