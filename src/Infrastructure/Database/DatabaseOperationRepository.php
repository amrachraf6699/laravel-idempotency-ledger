<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Infrastructure\Database;

use AmrAchraf\LaravelIdempotencyLedger\Contracts\OperationRepository;
use AmrAchraf\LaravelIdempotencyLedger\Domain\Operation;
use AmrAchraf\LaravelIdempotencyLedger\Domain\OperationIdentity;
use AmrAchraf\LaravelIdempotencyLedger\Domain\OperationState;
use AmrAchraf\LaravelIdempotencyLedger\Domain\StoredResponse;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use stdClass;

final class DatabaseOperationRepository implements OperationRepository
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function claim(OperationIdentity $identity, DateTimeInterface $now, DateTimeInterface $staleAfter): bool
    {
        try {
            $this->table()->insert([
                'id' => $identity->id,
                'scope_hash' => $identity->scopeHash,
                'key_hash' => $identity->keyHash,
                'operation_hash' => $identity->operationHash,
                'fingerprint_hash' => $identity->fingerprintHash,
                'state' => OperationState::Processing->value,
                'claim_token' => $identity->claimToken,
                'attempt' => 1,
                'processing_started_at' => $now,
                'stale_after_at' => $staleAfter,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function find(string $scopeHash, string $keyHash): ?Operation
    {
        $row = $this->table()->where('scope_hash', $scopeHash)->where('key_hash', $keyHash)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function reclaim(Operation $operation, string $claimToken, DateTimeInterface $now, DateTimeInterface $staleAfter): bool
    {
        return $this->table()->where('id', $operation->id)->where('state', OperationState::Retryable->value)->update([
            'state' => OperationState::Processing->value,
            'claim_token' => $claimToken,
            'attempt' => $operation->attempt + 1,
            'processing_started_at' => $now,
            'stale_after_at' => $staleAfter,
            'resolved_at' => null,
            'resolution_reason' => null,
            'updated_at' => $now,
        ]) === 1;
    }

    public function markStale(Operation $operation, DateTimeInterface $now): bool
    {
        return $this->table()->where('id', $operation->id)->where('state', OperationState::Processing->value)
            ->where('claim_token', $operation->claimToken)->where('stale_after_at', '<=', $now)->update([
                'state' => OperationState::Indeterminate->value,
                'claim_token' => null,
                'resolved_at' => $now,
                'resolution_reason' => 'stale_claim',
                'updated_at' => $now,
            ]) === 1;
    }

    public function complete(OperationIdentity $identity, StoredResponse $response, DateTimeInterface $now, DateTimeInterface $expiresAt): bool
    {
        return $this->finalize($identity, OperationState::Completed, [
            'resolved_at' => $now,
            'expires_at' => $expiresAt,
            'response_status' => $response->status,
            'response_content_type' => $response->contentType,
            'response_headers' => json_encode($response->headers, JSON_THROW_ON_ERROR),
            'response_body' => $response->body,
            'response_body_size' => $response->bodySize,
            'response_body_encrypted' => $response->bodyEncrypted,
            'replayable' => true,
            'resolution_reason' => null,
        ], $now);
    }

    public function markCompletedUnavailable(OperationIdentity $identity, string $reason, DateTimeInterface $now, DateTimeInterface $expiresAt): bool
    {
        return $this->finalize($identity, OperationState::Completed, [
            'resolved_at' => $now,
            'expires_at' => $expiresAt,
            'replayable' => false,
            'resolution_reason' => $reason,
        ], $now);
    }

    public function markRetryable(OperationIdentity $identity, DateTimeInterface $now): bool
    {
        return $this->finalize($identity, OperationState::Retryable, [
            'resolved_at' => $now,
            'resolution_reason' => 'retryable_exception',
        ], $now);
    }

    public function markIndeterminate(OperationIdentity $identity, string $reason, DateTimeInterface $now): bool
    {
        return $this->finalize($identity, OperationState::Indeterminate, [
            'resolved_at' => $now,
            'resolution_reason' => $reason,
        ], $now);
    }

    public function prune(DateTimeInterface $now): array
    {
        $stale = $this->table()->where('state', OperationState::Processing->value)->where('stale_after_at', '<=', $now)->update([
            'state' => OperationState::Indeterminate->value,
            'claim_token' => null,
            'resolved_at' => $now,
            'resolution_reason' => 'stale_claim',
            'updated_at' => $now,
        ]);

        $pruned = $this->table()->where('state', OperationState::Completed->value)->where('expires_at', '<=', $now)->delete();

        return ['stale' => $stale, 'pruned' => $pruned];
    }

    /** @param array<string, mixed> $values */
    private function finalize(OperationIdentity $identity, OperationState $state, array $values, DateTimeInterface $now): bool
    {
        return $this->table()->where('id', $identity->id)->where('state', OperationState::Processing->value)
            ->where('claim_token', $identity->claimToken)->update(array_merge($values, [
                'state' => $state->value,
                'claim_token' => null,
                'updated_at' => $now,
            ])) === 1;
    }

    private function table(): Builder
    {
        return $this->database->connection(config('idempotency.connection'))->table(config('idempotency.table', 'idempotency_operations'));
    }

    private function hydrate(stdClass $row): Operation
    {
        $headers = $row->response_headers === null ? null : json_decode((string) $row->response_headers, true, 512, JSON_THROW_ON_ERROR);

        return new Operation(
            id: (string) $row->id,
            scopeHash: (string) $row->scope_hash,
            keyHash: (string) $row->key_hash,
            operationHash: (string) $row->operation_hash,
            fingerprintHash: (string) $row->fingerprint_hash,
            state: OperationState::from((string) $row->state),
            claimToken: $row->claim_token === null ? null : (string) $row->claim_token,
            attempt: (int) $row->attempt,
            staleAfterAt: $this->date($row->stale_after_at),
            responseStatus: $row->response_status === null ? null : (int) $row->response_status,
            responseContentType: $row->response_content_type === null ? null : (string) $row->response_content_type,
            responseHeaders: $headers,
            responseBody: $row->response_body === null ? null : (string) $row->response_body,
            responseBodyEncrypted: (bool) $row->response_body_encrypted,
            replayable: (bool) $row->replayable,
            resolutionReason: $row->resolution_reason === null ? null : (string) $row->resolution_reason,
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }
}
