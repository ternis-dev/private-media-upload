<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PrivateWf\Api\Drivers;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hardening headers on every response. Referrer-Policy protects
 * E2EE fragments; HSTS only when serving https (never on localhost http,
 * where it would brick local dev in browsers).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (str_starts_with(Drivers::appUrl(), 'https://')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
