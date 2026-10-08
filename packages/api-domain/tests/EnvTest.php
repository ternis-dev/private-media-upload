<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Env;

final class EnvTest extends TestCase
{
    public function test_server_wins_over_env_and_getenv(): void
    {
        $_SERVER['PWF_TEST_KEY'] = 'server';
        $_ENV['PWF_TEST_KEY'] = 'env';
        putenv('PWF_TEST_KEY=getenv');
        try {
            $this->assertSame('server', Env::get('PWF_TEST_KEY'));
            unset($_SERVER['PWF_TEST_KEY']);
            $this->assertSame('env', Env::get('PWF_TEST_KEY'));
            unset($_ENV['PWF_TEST_KEY']);
            $this->assertSame('getenv', Env::get('PWF_TEST_KEY'));
            putenv('PWF_TEST_KEY');
            $this->assertSame('fallback', Env::get('PWF_TEST_KEY', 'fallback'));
        } finally {
            unset($_SERVER['PWF_TEST_KEY'], $_ENV['PWF_TEST_KEY']);
            putenv('PWF_TEST_KEY');
        }
    }
}
