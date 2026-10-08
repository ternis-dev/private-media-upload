<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * private.wf schema (mirrors PrivateWf\Api\Store::migrate 1:1).
 * Primary target: pgsql prod. sqlite dev/test is created by the domain Store.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('tier');
            $t->string('filename');
            $t->string('mime');
            $t->bigInteger('expected');
            $t->bigInteger('received')->default(0);
            $t->string('storage_key');
            $t->string('status')->default('open');
            $t->bigInteger('created_at');
            $t->string('owner_id')->nullable();
        });
        Schema::create('assets', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('tier');
            $t->string('storage_key');
            $t->bigInteger('size');
            $t->string('sha256');
            $t->string('mime');
            $t->string('filename');
            $t->bigInteger('created_at');
            $t->string('owner_id')->nullable();
            $t->integer('scanned')->default(0);
            $t->integer('e2ee')->default(0);
            $t->string('thumb_key')->nullable();
        });
        Schema::create('shares', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('asset_id');
            $t->string('tier');
            $t->bigInteger('expires_at');
            $t->bigInteger('created_at');
            $t->text('password_hash')->nullable();
            $t->bigInteger('max_views')->nullable();
            $t->bigInteger('views')->default(0);
            $t->integer('burn')->default(0);
            $t->bigInteger('revoked_at')->nullable();
            $t->index('expires_at');
        });
        Schema::create('access_log', function (Blueprint $t): void {
            $t->id();
            $t->string('share_id')->index();
            $t->string('ip_hash');
            $t->string('ua_hash');
            $t->string('result');
            $t->bigInteger('at');
        });
        Schema::create('ratelimits', function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->bigInteger('count');
            $t->bigInteger('window_start');
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('email')->unique();
            $t->text('pw_hash');
            $t->bigInteger('quota_bytes');
            $t->bigInteger('created_at');
        });
        Schema::create('tokens', function (Blueprint $t): void {
            $t->string('token_hash')->primary();
            $t->string('user_id')->index();
            $t->string('name');
            $t->bigInteger('created_at');
            $t->bigInteger('last_used')->nullable();
        });
        Schema::create('reports', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('share_id');
            $t->string('reason');
            $t->string('contact')->nullable();
            $t->string('status')->default('open');
            $t->bigInteger('created_at');
            $t->index('status');
        });
    }

    public function down(): void
    {
        foreach (['reports', 'tokens', 'users', 'ratelimits', 'access_log', 'shares', 'assets', 'uploads'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
