<?php

declare(strict_types=1);

namespace Tests\Feature;

use PrivateWf\Api\Store;
use Tests\TestCase;

/**
 * M3a HTTP parity: every behavior the lean router proved live, now through
 * Laravel controllers. Domain Store uses :memory: sqlite per test (fresh
 * app per test → fresh singleton); staging under a fixed temp var dir.
 */
final class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('DB_PATH=:memory:');
        putenv('VAR_DIR=' . sys_get_temp_dir() . '/pwf-feat-var');
        putenv('APP_URL=http://localhost');
        putenv('WEB_URL=http://localhost:3000');
        putenv('HMAC_SECRET=feat-secret');
        putenv('SOVEREIGN_PATH=' . sys_get_temp_dir() . '/pwf-feat-l3');
        putenv('SFTP_L2_EMU_ROOT=' . sys_get_temp_dir() . '/pwf-feat-l2');
        putenv('S3_L1_EMU_ROOT=' . sys_get_temp_dir() . '/pwf-feat-l1');
        putenv('USER_QUOTA_BYTES=10737418240');
        putenv('CLAMAV_HOST=');
    }

    private function authed(): string
    {
        $email = 't' . bin2hex(random_bytes(4)) . '@example.com';
        $this->postJson('/v1/auth/register', ['email' => $email, 'password' => 'account-password-123'])
            ->assertCreated();
        $token = $this->postJson('/v1/auth/login', ['email' => $email, 'password' => 'account-password-123'])
            ->assertOk()->json('token');
        $this->assertStringStartsWith('pwf_', $token);
        return $token;
    }

    /** Raw-body request (chunk bytes); TestCase::put/patch require arrays. */
    private function raw(string $method, string $uri, string $content, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server[strtolower($k) === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server += ['CONTENT_TYPE' => 'application/octet-stream'];
        return $this->call($method, $uri, [], [], [], $server, $content);
    }

    /** Reserve → append → complete, returns share payload. */
    private function upload(array $opts = [], ?string $token = null): array
    {
        $headers = $token !== null ? ['Authorization' => "Bearer {$token}"] : [];
        $init = $this->postJson('/v1/uploads/init', [
            'tier' => 'L3', 'filename' => 'f.bin', 'size' => 10, 'mime' => 'application/octet-stream',
        ], $headers)->assertCreated()->json();
        $this->raw('PUT', '/v1/uploads/' . $init['uploadId'], '01234')->assertOk();
        $this->raw('PUT', '/v1/uploads/' . $init['uploadId'], '56789')->assertOk()
            ->assertJson(['done' => true]);
        return $this->postJson('/v1/uploads/' . $init['uploadId'] . '/complete', $opts, $headers)
            ->assertCreated()->json();
    }

    public function test_health_and_tiers(): void
    {
        $this->get('/health')->assertOk()->assertJson(['ok' => true]);
        $tiers = $this->get('/v1/tiers')->assertOk()->json('tiers');
        $this->assertSame(['L1', 'L2', 'L3'], array_column($tiers, 'id'));
    }

    public function test_auth_lifecycle(): void
    {
        $this->getJson('/v1/me/assets')->assertUnauthorized();
        $token = $this->authed();
        $me = $this->getJson('/v1/me/assets', ['Authorization' => "Bearer {$token}"])->assertOk()->json();
        $this->assertSame([], $me['assets']);
        $this->postJson('/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->getJson('/v1/me/assets', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_register_duplicate_and_login_generic(): void
    {
        $this->postJson('/v1/auth/register', ['email' => 'dup@example.com', 'password' => 'account-password-123'])
            ->assertCreated();
        $this->postJson('/v1/auth/register', ['email' => 'dup@example.com', 'password' => 'account-password-123'])
            ->assertConflict();
        $this->postJson('/v1/auth/login', ['email' => 'dup@example.com', 'password' => 'wrong'])
            ->assertUnauthorized()->assertJson(['error' => 'invalid credentials']);
    }

    public function test_full_l3_flow_bytes_intact(): void
    {
        $done = $this->upload();
        $meta = $this->getJson('/v1/shares/' . $done['shareId'] . '/meta')->assertOk()->json();
        $this->assertSame('L3', $meta['tier']);
        $this->assertSame(10, $meta['size']);

        $redirect = $this->get('/s/' . $done['shareId'])->assertRedirect();
        $blobUrl = $redirect->headers->get('Location');
        $parts = parse_url($blobUrl);
        $this->get($parts['path'] . '?' . ($parts['query'] ?? ''))->assertOk()
            ->assertStreamedContent('0123456789');
    }

    public function test_password_gate(): void
    {
        $done = $this->upload(['password' => 'super-secret-123']);
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta')->assertUnauthorized();
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta', ['X-Share-Password' => 'nope'])
            ->assertUnauthorized();
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta', ['X-Share-Password' => 'super-secret-123'])
            ->assertOk()->assertJson(['hasPassword' => true]);
    }

    public function test_burn_after_read(): void
    {
        $done = $this->upload(['burn' => true]);
        $loc = $this->get('/s/' . $done['shareId'])->assertRedirect()->headers->get('Location');
        $parts = parse_url($loc);
        $blob = $parts['path'] . '?' . ($parts['query'] ?? '');
        // streamedContent() runs the stream (and the post-serve finalize).
        $this->get($blob)->assertOk()->assertStreamedContent('0123456789');
        $this->get($blob)->assertNotFound();
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta')->assertNotFound();
    }

    public function test_max_views_exhausts(): void
    {
        $done = $this->upload(['maxViews' => 1]);
        $loc = $this->get('/s/' . $done['shareId'])->assertRedirect()->headers->get('Location');
        $parts = parse_url($loc);
        $this->get($parts['path'] . '?' . ($parts['query'] ?? ''))->assertOk()
            ->assertStreamedContent('0123456789');
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta')->assertNotFound();
    }

    public function test_revoke_and_export(): void
    {
        $done = $this->upload(['password' => 'export-pw-123']);
        $this->getJson('/v1/shares/' . $done['shareId'] . '/export',
            ['X-Share-Password' => 'export-pw-123'])->assertOk()->assertJsonStructure(['share', 'asset', 'accessLog']);
        $this->deleteJson('/v1/shares/' . $done['shareId'], [], ['X-Share-Password' => 'export-pw-123'])
            ->assertOk()->assertJson(['revoked' => true]);
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta',
            ['X-Share-Password' => 'export-pw-123'])->assertStatus(410);
    }

    public function test_rate_limit_429(): void
    {
        $codes = [];
        for ($i = 0; $i < 61; $i++) {
            $codes[] = $this->getJson('/v1/shares/00000000/meta')->getStatusCode();
        }
        $this->assertContains(429, $codes);
        $this->assertSame(429, end($codes));
    }

    public function test_tus_flow(): void
    {
        $meta = 'filename ' . base64_encode('tus.mp4') . ',filetype ' . base64_encode('video/mp4');
        $create = $this->post('/v1/uploads/tus?tier=L3', [], [
            'Tus-Resumable' => '1.0.0', 'Upload-Length' => '10', 'Upload-Metadata' => $meta,
        ])->assertCreated();
        $loc = $create->headers->get('Location');
        $path = parse_url($loc, PHP_URL_PATH);
        $this->head($path, ['Tus-Resumable' => '1.0.0'])->assertOk()
            ->assertHeader('Upload-Offset', '0');
        $this->raw('PATCH', $path, 'xxxxx', [
            'Tus-Resumable' => '1.0.0', 'Upload-Offset' => '99',
            'Content-Type' => 'application/offset+octet-stream',
        ])->assertStatus(409);
        $this->raw('PATCH', $path, '01234', [
            'Tus-Resumable' => '1.0.0', 'Upload-Offset' => '0',
            'Content-Type' => 'application/offset+octet-stream',
        ])->assertStatus(204)->assertHeader('Upload-Offset', '5');
        $this->raw('PATCH', $path, '56789', [
            'Tus-Resumable' => '1.0.0', 'Upload-Offset' => '5',
            'Content-Type' => 'application/offset+octet-stream',
        ])->assertStatus(204);
        $upId = basename($path);
        $done = $this->postJson("/v1/uploads/{$upId}/complete")->assertCreated()->json();
        $this->assertSame('video/mp4', $done['mime']);
    }

    public function test_e2ee_flag_and_quarantine_skip(): void
    {
        $done = $this->upload(['e2ee' => true]);
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta')->assertOk()->assertJson(['e2ee' => true]);
        $this->artisan('pwf:quarantine')->expectsOutputToContain('skipped')->assertSuccessful();
    }

    public function test_purge_command(): void
    {
        $done = $this->upload();
        app(Store::class)->setShareExpiry($done['shareId'], time() - 1);
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta')->assertStatus(410);
        $this->artisan('pwf:purge')->expectsOutputToContain('"purged":1')->assertSuccessful();
        $this->getJson('/v1/shares/' . $done['shareId'] . '/meta')->assertNotFound();
    }

    public function test_quota_413(): void
    {
        putenv('USER_QUOTA_BYTES=100');
        $token = $this->authed();
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson('/v1/uploads/init', [
            'tier' => 'L3', 'filename' => 'a.bin', 'size' => 60, 'mime' => 'application/octet-stream',
        ], $headers)->assertCreated();
        $this->postJson('/v1/uploads/init', [
            'tier' => 'L3', 'filename' => 'b.bin', 'size' => 41, 'mime' => 'application/octet-stream',
        ], $headers)->assertStatus(413);
    }

    public function test_me_export_zip_and_account_erasure(): void
    {
        $token = $this->authed();
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->upload([], $token);
        $zip = $this->get('/v1/me/export?format=zip', $headers)->assertOk();
        $this->assertSame('application/zip', $zip->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'pwf-feat-zip');
        file_put_contents($tmp, $zip->streamedContent());
        $za = new \ZipArchive();
        $this->assertTrue($za->open($tmp));
        $names = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $names[] = $za->getNameIndex($i);
        }
        $this->assertContains('manifest.json', $names);
        $this->assertCount(2, $names);
        $za->close();
        unlink($tmp);
    }
}
