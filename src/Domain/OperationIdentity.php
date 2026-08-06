<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Domain;

final readonly class OperationIdentity
{
    public function __construct(
        public string $id,
        public string $scopeHash,
        public string $keyHash,
        public string $operationHash,
        public string $fingerprintHash,
        public string $claimToken,
    ) {}
}
