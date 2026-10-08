<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 401 unless OptionalBearer found a user. */
final class RequireBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('apiUser') === null) {
            throw new ApiError(401, ['error' => 'unauthorized']);
        }
        return $next($request);
    }
}
