<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class RetryableIdempotencyException extends HttpException
{
    public function __construct(string $message = 'The operation can be retried safely.')
    {
        parent::__construct(503, $message, null, ['X-Idempotency-Retryable' => 'true']);
    }
}
