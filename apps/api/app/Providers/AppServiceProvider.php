<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PrivateWf\Api\ClamAv;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Store::class, fn () => Store::openFromEnv());
        $this->app->singleton(ClamAv::class, fn () => ClamAv::fromEnv());
        $this->app->singleton(UploadService::class, function () {
            $clam = app(ClamAv::class);
            return new UploadService(app(Store::class), Drivers::webUrl(),
                $clam->enabled() ? $clam->scanFile(...) : null);
        });
    }

    public function boot(): void
    {
        // Contract routes without the default /api prefix (no middleware;
        // auth + rate limits are per-route, mirroring the lean router 1:1).
        Route::group([], base_path('routes/api.php'));
    }
}
