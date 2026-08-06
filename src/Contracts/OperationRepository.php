<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Contracts;

use AmrAchraf\LaravelIdempotencyLedger\Domain\Operation;
use AmrAchraf\LaravelIdempotencyLedger\Domain\OperationIdentity;
use AmrAchraf\LaravelIdempotencyLedger\Domain\StoredResponse;
use DateTimeInterface;

interface OperationRepository
{
    /** @phpstan-impure */
    public function claim(OperationIdentity $identity, DateTimeInterface $now, DateTimeInterface $staleAfter): bool;

    public function find(string $scopeHash, string $keyHash): ?Operation;

    public function reclaim(Operation $operation, string $claimToken, DateTimeInterface $now, DateTimeInterface $staleAfter): bool;

    public function markStale(Operation $operation, DateTimeInterface $now): bool;

    public function complete(OperationIdentity $identity, StoredResponse $response, DateTimeInterface $now, DateTimeInterface $expiresAt): bool;

    public function markCompletedUnavailable(OperationIdentity $identity, string $reason, DateTimeInterface $now, DateTimeInterface $expiresAt): bool;

    public function markRetryable(OperationIdentity $identity, DateTimeInterface $now): bool;

    public function markIndeterminate(OperationIdentity $identity, string $reason, DateTimeInterface $now): bool;

    /** @return array{stale: int, pruned: int} */
    public function prune(DateTimeInterface $now): array;
}
