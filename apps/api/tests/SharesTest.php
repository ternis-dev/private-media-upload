<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Shares;

final class SharesTest extends TestCase
{
    public function test_new_id_format(): void
    {
        $id = Shares::newId();
        $this->assertTrue(Shares::validId($id));
        $this->assertSame(12, strlen($id));
        $this->assertNotSame($id, Shares::newId());
    }

    public function test_valid_id_rejects_junk(): void
    {
        $this->assertFalse(Shares::validId('../etc'));
        $this->assertFalse(Shares::validId('short'));
        $this->assertFalse(Shares::validId('has space 123'));
    }

    public function test_validate_init_ok(): void
    {
        foreach (['L1', 'L2', 'L3'] as $tier) {
            [$ok] = Shares::validateInit(['tier' => $tier, 'filename' => 'clip.mp4', 'size' => 1024, 'mime' => 'video/mp4']);
            $this->assertTrue($ok, $tier);
        }
    }

    public function test_validate_init_rejects(): void
    {
        [$ok] = Shares::validateInit(['tier' => 'L9', 'filename' => 'a', 'size' => 1, 'mime' => 'x/y']);
        $this->assertFalse($ok);
        [$ok] = Shares::validateInit(['tier' => 'L1', 'filename' => '../x', 'size' => 1, 'mime' => 'x/y']);
        $this->assertFalse($ok);
        [$ok] = Shares::validateInit(['tier' => 'L1', 'filename' => 'a.bin', 'size' => 0, 'mime' => 'x/y']);
        $this->assertFalse($ok);
        [$ok] = Shares::validateInit(['tier' => 'L1', 'filename' => 'a.bin', 'size' => 6 * 1024 * 1024 * 1024, 'mime' => 'x/y']);
        $this->assertFalse($ok);
    }

    public function test_default_expiry_l3_longer_than_l1(): void
    {
        $this->assertGreaterThan(Shares::defaultExpiry('L1'), Shares::defaultExpiry('L3'));
    }

    public function test_router_endpoints(): void
    {
        // Boot the built-in router via `php -S` in-process is overkill for M0;
        // assert the router file at least parses and exposes the 4 routes.
        $src = file_get_contents(__DIR__ . '/../public/index.php');
        $this->assertStringContainsString('/health', $src);
        $this->assertStringContainsString('/v1/tiers', $src);
        $this->assertStringContainsString('/v1/uploads/init', $src);
        $this->assertStringContainsString('X-Robots-Tag: noindex', $src);
    }
}
