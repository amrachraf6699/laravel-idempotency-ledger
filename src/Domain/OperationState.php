<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Domain;

enum OperationState: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Retryable = 'retryable';
    case Indeterminate = 'indeterminate';
}
