<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use PrivateWf\Api\ClamAv;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;

/**
 * Deep health for monitoring (M4). Always 200 (dashboards shouldn't flap);
 * `ok:false` when degraded. Probe objects are tiny and deleted immediately.
 */
final class HealthController extends Controller
{
    public function __construct(private Store $store, private ClamAv $clam)
    {
    }

    public function deep(): JsonResponse
    {
        $storage = [];
        $ok = $this->store->ping();
        $checks = ['db' => $ok ? 'up' : 'down'];
        foreach (['L1', 'L2', 'L3'] as $tier) {
            try {
                $driver = Drivers::forTier($tier);
                $probeKey = 'u/_probe/' . bin2hex(random_bytes(8)) . '.bin';
                $mode = method_exists($driver, 'isReal') && $driver->isReal() ? 'real' : 'emulation';
                $driver->put($probeKey, 'probe');
                $readOk = $driver->get($probeKey) === 'probe';
                $driver->delete($probeKey);
                $storage[$tier] = $readOk ? "up ({$mode})" : 'down (mismatch)';
                $ok = $ok && $readOk;
            } catch (\Throwable $e) {
                $storage[$tier] = 'down (' . substr($e->getMessage(), 0, 80) . ')';
                $ok = false;
            }
        }
        $checks['storage'] = $storage;
        $checks['clamav'] = !$this->clam->enabled() ? 'skipped' : ($this->clam->ping() ? 'up' : 'down');
        return response()->json(['ok' => $ok, 'checks' => $checks]);
    }
}
