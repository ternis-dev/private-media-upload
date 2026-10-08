<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PrivateWf\Api\UploadService;
use Symfony\Component\HttpFoundation\Response;

/** Attach Bearer user (or null) — public endpoints stay public. */
final class OptionalBearer
{
    public function __construct(private UploadService $svc)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = null;
        if (preg_match('/^Bearer\s+(\S+)$/', $request->header('Authorization', ''), $m)) {
            $bearer = $m[1];
        }
        $request->attributes->set('apiUser', $this->svc->userFromToken($bearer));
        return $next($request);
    }
}
