<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** JSON API error with a fixed payload shape. Rendered in bootstrap/app.php. */
final class ApiError extends HttpException
{
    public function __construct(
        int $status,
        public readonly array $payload,
        array $headers = [],
    ) {
        parent::__construct($status, $payload['error'] ?? 'error', null, $headers);
    }
}
