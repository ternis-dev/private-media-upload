<?php

declare(strict_types=1);

namespace PrivateWf\Api\Tests;

use PHPUnit\Framework\TestCase;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\LocalSovereignDriver;

/** M2b: token auth, quotas, per-user export + cascade erasure. */
final class AuthTest extends TestCase
{
    private Store $store;
    private UploadService $svc;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pwf-auth-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        $this->store = Store::memory($tmp . '/var');
        $this->svc = new UploadService($this->store, 'http://localhost:3000');
    }

    public function test_register_login_token_roundtrip(): void
    {
        $u = $this->svc->register('Ada@Example.COM', 'account-password-123', 1000);
        $this->assertStringStartsWith('us_', $u['id']);
        // Email normalized; hash stored, not plaintext.
        $row = $this->store->findUserByEmail('ada@example.com');
        $this->assertNotSame('account-password-123', $row['pw_hash']);

        $t = $this->svc->login('ada@example.com', 'account-password-123', 'laptop');
        $this->assertStringStartsWith('pwf_', $t['token']);
        $me = $this->svc->userFromToken($t['token']);
        $this->assertSame('ada@example.com', $me['email']);
        // Token stored hashed.
        $this->assertNull($this->store->findUserByTokenHash($t['token'], time()));
    }

    public function test_login_rejects_without_enumeration(): void
    {
        $this->svc->register('grace@example.com', 'account-password-123', 1000);
        foreach (['nouser@example.com', 'grace@example.com'] as $email) {
            try {
                $this->svc->login($email, 'wrong-password');
                $this->fail('expected invalid credentials');
            } catch (\RuntimeException $e) {
                $this->assertSame('invalid credentials', $e->getMessage());
            }
        }
    }

    public function test_register_validation_and_duplicates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->register('not-an-email', 'account-password-123', 1000);
    }

    public function test_duplicate_email_rejected(): void
    {
        $this->svc->register('dup@example.com', 'account-password-123', 1000);
        $this->expectException(\RuntimeException::class);
        $this->svc->register('dup@example.com', 'account-password-123', 1000);
    }

    public function test_short_account_password_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->register('s@example.com', 'too-short', 1000);
    }

    public function test_logout_revokes_token(): void
    {
        $this->svc->register('out@example.com', 'account-password-123', 1000);
        $t = $this->svc->login('out@example.com', 'account-password-123');
        $this->assertNotNull($this->svc->userFromToken($t['token']));
        $this->store->revokeToken(hash('sha256', $t['token']));
        $this->assertNull($this->svc->userFromToken($t['token']));
    }

    public function test_quota_enforced_on_reserve(): void
    {
        $u = $this->svc->register('q@example.com', 'account-password-123', 100);
        $this->svc->reserve('L3', 'a.bin', 60, 'application/octet-stream', $u['id'], 100);
        try {
            $this->svc->reserve('L3', 'b.bin', 41, 'application/octet-stream', $u['id'], 100);
            $this->fail('expected quota exceeded');
        } catch (\RuntimeException $e) {
            $this->assertSame('quota exceeded', $e->getMessage());
        }
    }

    public function test_cascade_erasure_wipes_everything(): void
    {
        $driver = new LocalSovereignDriver(sys_get_temp_dir() . '/pwf-authz-' . bin2hex(random_bytes(4)));
        $u = $this->svc->register('del@example.com', 'account-password-123', 100000);
        $t = $this->svc->login('del@example.com', 'account-password-123');
        $r = $this->svc->reserve('L3', 'mine.bin', 4, 'application/octet-stream', $u['id'], 100000);
        $this->svc->append($r['uploadId'], 'data');
        $done = $this->svc->complete($r['uploadId'], $driver);
        $key = $this->store->getShare($done['shareId'])['storage_key'];
        $this->assertTrue($driver->exists($key));

        $n = $this->svc->deleteUserAccount($u['id'], fn () => $driver);
        $this->assertSame(1, $n);
        $this->assertFalse($driver->exists($key));
        $this->assertNull($this->store->findUserByEmail('del@example.com'));
        $this->assertNull($this->svc->userFromToken($t['token']));
        $this->assertSame([], $this->store->assetsFor($u['id']));
    }

    public function test_export_user_manifest(): void
    {
        $driver = new LocalSovereignDriver(sys_get_temp_dir() . '/pwf-authe-' . bin2hex(random_bytes(4)));
        $u = $this->svc->register('exp@example.com', 'account-password-123', 100000);
        $r = $this->svc->reserve('L3', 'e.bin', 3, 'application/octet-stream', $u['id'], 100000);
        $this->svc->append($r['uploadId'], 'xyz');
        $done = $this->svc->complete($r['uploadId'], $driver);
        $this->store->logAccess($done['shareId'], 'h', 'u', 'blob-ok', 1);
        $m = $this->svc->exportUser($u['id']);
        $this->assertCount(1, $m['assets']);
        $this->assertSame($done['shareId'], $m['assets'][0]['share_id']);
        $this->assertSame('blob-ok', $m['accessLog'][0]['result']);
    }
}
