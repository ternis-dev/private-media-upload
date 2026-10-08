<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;
use PrivateWf\Api\Store;
use Symfony\Component\HttpFoundation\Response;

/** Fixed-window gate backed by the domain limiter (SQLite now, Redis later). */
final class ApiRateLimit
{
    public function __construct(private Store $store)
    {
    }

    public function handle(Request $request, Closure $next, string $name, int $limit = 60, int $window = 60): Response
    {
        $r = $this->store->rateHit("{$name}:" . $request->ip(), $limit, $window, time());
        $response = $r['allowed']
            ? $next($request)
            : throw new ApiError(429, ['error' => 'rate limited', 'retryAfter' => $r['reset']],
                ['Retry-After' => (string) max(1, $r['reset'] - time())]);
        $response->headers->set('X-RateLimit-Remaining', (string) $r['remaining']);
        return $response;
    }
}
