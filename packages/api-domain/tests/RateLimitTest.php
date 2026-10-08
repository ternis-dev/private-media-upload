<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;

final class RateLimitTest extends TestCase
{
    public function test_fixed_window(): void
    {
        $store = Store::memory(sys_get_temp_dir() . '/pwf-rl-' . bin2hex(random_bytes(4)) . '/var');
        $now = 1000000;
        $this->assertTrue($store->rateHit('k', 2, 60, $now)['allowed']);
        $r = $store->rateHit('k', 2, 60, $now);
        $this->assertTrue($r['allowed']);
        $this->assertSame(0, $r['remaining']);
        $r = $store->rateHit('k', 2, 60, $now);
        $this->assertFalse($r['allowed']);
        $this->assertSame($now + 60, $r['reset']);
        // Window rolls over.
        $this->assertTrue($store->rateHit('k', 2, 60, $now + 61)['allowed']);
    }

    public function test_keys_are_independent(): void
    {
        $store = Store::memory(sys_get_temp_dir() . '/pwf-rl-' . bin2hex(random_bytes(4)) . '/var');
        $store->rateHit('a', 1, 60, 1000000);
        $this->assertFalse($store->rateHit('a', 1, 60, 1000000)['allowed']);
        $this->assertTrue($store->rateHit('b', 1, 60, 1000000)['allowed']);
    }
}

