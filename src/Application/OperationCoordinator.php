<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Application;

use AmrAchraf\LaravelIdempotencyLedger\Contracts\OperationRepository;
use AmrAchraf\LaravelIdempotencyLedger\Domain\Acquisition;
use AmrAchraf\LaravelIdempotencyLedger\Domain\OperationIdentity;
use AmrAchraf\LaravelIdempotencyLedger\Domain\OperationState;
use AmrAchraf\LaravelIdempotencyLedger\Domain\StoredResponse;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;

final class OperationCoordinator
{
    public function __construct(private readonly OperationRepository $operations) {}

    public function acquire(OperationIdentity $identity): Acquisition
    {
        $now = new DateTimeImmutable;
        $staleAfter = $now->add(new DateInterval('PT'.max(1, (int) config('idempotency.stale_after', 900)).'S'));

        if ($this->operations->claim($identity, $now, $staleAfter)) {
            return Acquisition::owned();
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $operation = $this->operations->find($identity->scopeHash, $identity->keyHash);

            if ($operation === null) {
                if ($this->operations->claim($identity, $now, $staleAfter)) {
                    return Acquisition::owned();
                }

                continue;
            }

            if ($operation->state === OperationState::Processing
                && $operation->staleAfterAt !== null
                && $operation->staleAfterAt <= $now) {
                $this->operations->markStale($operation, $now);

                continue;
            }

            if ($operation->state === OperationState::Retryable
                && $this->operations->reclaim($operation, $identity->claimToken, $now, $staleAfter)) {
                return Acquisition::owned();
            }

            return Acquisition::existing($operation);
        }

        throw new RuntimeException('Unable to acquire an idempotency operation after concurrent changes.');
    }

    public function complete(OperationIdentity $identity, StoredResponse $response): bool
    {
        $now = new DateTimeImmutable;
        $expiresAt = $now->add(new DateInterval('PT'.max(1, (int) config('idempotency.ttl', 86_400)).'S'));

        return $this->operations->complete($identity, $response, $now, $expiresAt);
    }

    public function completeUnavailable(OperationIdentity $identity, string $reason): bool
    {
        $now = new DateTimeImmutable;
        $expiresAt = $now->add(new DateInterval('PT'.max(1, (int) config('idempotency.ttl', 86_400)).'S'));

        return $this->operations->markCompletedUnavailable($identity, $reason, $now, $expiresAt);
    }

    public function retryable(OperationIdentity $identity): bool
    {
        return $this->operations->markRetryable($identity, new DateTimeImmutable);
    }

    public function indeterminate(OperationIdentity $identity, string $reason): bool
    {
        return $this->operations->markIndeterminate($identity, $reason, new DateTimeImmutable);
    }
}
