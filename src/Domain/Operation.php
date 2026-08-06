<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Domain;

use DateTimeImmutable;

final readonly class Operation
{
    /** @param array<string, list<string>>|null $responseHeaders */
    public function __construct(
        public string $id,
        public string $scopeHash,
        public string $keyHash,
        public string $operationHash,
        public string $fingerprintHash,
        public OperationState $state,
        public ?string $claimToken,
        public int $attempt,
        public ?DateTimeImmutable $staleAfterAt,
        public ?int $responseStatus,
        public ?string $responseContentType,
        public ?array $responseHeaders,
        public ?string $responseBody,
        public bool $responseBodyEncrypted,
        public bool $replayable,
        public ?string $resolutionReason,
    ) {}
}
