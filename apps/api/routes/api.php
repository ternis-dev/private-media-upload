<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlobController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\ShortLinkController;
use App\Http\Controllers\TusController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;
use PrivateWf\Api\Shares;
use PrivateWf\Api\Store;

Route::get('/v1/health', [\App\Http\Controllers\HealthController::class, 'deep']);

// Contract identical to the lean router (openapi 0.4.0). No /api prefix,
// no session/CSRF middleware — auth is Bearer, limits are per-route.
Route::get('/health', fn (Store $store, \PrivateWf\Api\ClamAv $clam) => response()->json([
    'ok' => true, 'service' => 'private-wf-api', 'm' => 'M3a-laravel',
    'scanner' => $clam->enabled() ? 'clamav' : 'skipped',
]));

Route::get('/v1/tiers', fn () => response()->json(['tiers' => [
    ['id' => 'L1', 'name' => 'Edge Object Storage', 'residency' => 'global-edge', 'badge' => Shares::badge('L1'), 'backend' => 'Cloudflare R2 (S3-compatible)'],
    ['id' => 'L2', 'name' => 'Provider Vault DE', 'residency' => 'DE-only', 'badge' => Shares::badge('L2'), 'backend' => 'SFTP vault (single location)'],
    ['id' => 'L3', 'name' => 'Sovereign Node', 'residency' => 'DE-only', 'badge' => Shares::badge('L3'), 'backend' => 'own server (local disk)'],
]]));

Route::post('/v1/auth/register', [AuthController::class, 'register'])->middleware('pwf.rate:auth,10,3600');
Route::post('/v1/auth/login', [AuthController::class, 'login'])->middleware('pwf.rate:login,5,60');
Route::post('/v1/auth/logout', [AuthController::class, 'logout']);

Route::post('/v1/uploads/init', [UploadController::class, 'init'])
    ->middleware(['pwf.bearer', 'pwf.rate:init,30,3600']);
Route::match(['put', 'post'], '/v1/uploads/{uploadId}', [UploadController::class, 'append'])
    ->where('uploadId', 'up_[0-9A-Za-z]{8,32}')->middleware('pwf.rate:append,600,3600');
Route::post('/v1/uploads/{uploadId}/complete', [UploadController::class, 'complete'])
    ->where('uploadId', 'up_[0-9A-Za-z]{8,32}')->middleware('pwf.rate:complete,60,3600');
Route::post('/v1/uploads/l1-complete', [UploadController::class, 'l1Complete'])
    ->middleware(['pwf.bearer', 'pwf.rate:complete,60,3600']);

Route::options('/v1/uploads/tus', [TusController::class, 'options']);
Route::options('/v1/uploads/tus/{uploadId}', [TusController::class, 'options'])
    ->where('uploadId', 'up_[0-9A-Za-z]{8,32}');
Route::post('/v1/uploads/tus', [TusController::class, 'create'])
    ->middleware(['pwf.bearer', 'pwf.rate:init,30,3600']);
Route::match(['head'], '/v1/uploads/tus/{uploadId}', [TusController::class, 'head'])
    ->where('uploadId', 'up_[0-9A-Za-z]{8,32}');
Route::patch('/v1/uploads/tus/{uploadId}', [TusController::class, 'patch'])
    ->where('uploadId', 'up_[0-9A-Za-z]{8,32}')->middleware('pwf.rate:append,600,3600');
Route::delete('/v1/uploads/tus/{uploadId}', [TusController::class, 'destroy'])
    ->where('uploadId', 'up_[0-9A-Za-z]{8,32}');

Route::get('/v1/shares/{id}/meta', [ShareController::class, 'meta'])
    ->where('id', '[0-9A-Za-z]{8,32}')->middleware('pwf.rate:guess,60,60');
Route::get('/v1/shares/{id}/export', [ShareController::class, 'export'])
    ->where('id', '[0-9A-Za-z]{8,32}')->middleware('pwf.rate:export,60,3600');
Route::delete('/v1/shares/{id}', [ShareController::class, 'destroy'])
    ->where('id', '[0-9A-Za-z]{8,32}')->middleware('pwf.rate:export,60,3600');
Route::post('/v1/shares/{id}/report', [ShareController::class, 'report'])
    ->where('id', '[0-9A-Za-z]{8,32}')->middleware('pwf.rate:report,10,3600');

Route::get('/v1/blobs/{key}', [BlobController::class, 'show'])
    ->where('key', '.*')->middleware('pwf.rate:blob,120,60');

Route::get('/s/{id}', [ShortLinkController::class, 'show'])
    ->where('id', '[0-9A-Za-z]{8,32}')->middleware('pwf.rate:guess,60,60');

Route::middleware(['pwf.bearer', 'pwf.auth'])->group(function () {
    Route::get('/v1/me/assets', [MeController::class, 'assets']);
    Route::get('/v1/me/export', [MeController::class, 'export']);
    Route::delete('/v1/me', [MeController::class, 'destroy']);
});
