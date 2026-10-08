<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Env;

final class EnvTest extends TestCase
{
    public function test_server_wins_over_env_and_getenv(): void
    {
        putenv('PWF_TEST_KEY=getenv');
        $_ENV['PWF_TEST_KEY'] = 'env';
        $_SERVER['PWF_TEST_KEY'] = 'server';
        try {
            // Real environment (putenv) wins — this is what makes test
            // isolation and container overrides behave.
            $this->assertSame('getenv', Env::get('PWF_TEST_KEY'));
            putenv('PWF_TEST_KEY');
            $this->assertSame('server', Env::get('PWF_TEST_KEY'));
            unset($_SERVER['PWF_TEST_KEY']);
            $this->assertSame('env', Env::get('PWF_TEST_KEY'));
            unset($_ENV['PWF_TEST_KEY']);
            $this->assertSame('fallback', Env::get('PWF_TEST_KEY', 'fallback'));
        } finally {
            unset($_SERVER['PWF_TEST_KEY'], $_ENV['PWF_TEST_KEY']);
            putenv('PWF_TEST_KEY');
        }
    }
}
