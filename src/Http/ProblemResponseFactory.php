<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Http;

use Illuminate\Http\JsonResponse;

final class ProblemResponseFactory
{
    public function missingKey(): JsonResponse
    {
        return $this->make(400, 'missing-key', 'Idempotency key is required', 'Supply a valid Idempotency-Key header.');
    }

    public function fingerprintMismatch(string $operationId): JsonResponse
    {
        return $this->make(422, 'fingerprint-mismatch', 'Idempotency key was reused with a different request', 'Reuse a key only for the exact same operation and payload.', $operationId);
    }

    public function inProgress(string $operationId): JsonResponse
    {
        return $this->make(409, 'in-progress', 'An operation is already in progress for this idempotency key', 'Retry this exact request shortly.', $operationId, ['Retry-After' => '1']);
    }

    public function indeterminate(string $operationId): JsonResponse
    {
        return $this->make(409, 'outcome-unknown', 'The operation outcome is unknown', 'Do not retry automatically. Reconcile the business operation before using a new key.', $operationId);
    }

    public function responseUnavailable(string $operationId): JsonResponse
    {
        return $this->make(409, 'response-unavailable', 'The operation completed but its response cannot be replayed', 'Do not retry automatically. Query or reconcile the resulting resource.', $operationId);
    }

    public function retryableFailure(string $operationId, string $detail): JsonResponse
    {
        return $this->make(503, 'retryable-failure', 'The operation can be retried safely', $detail, $operationId);
    }

    public function scopeUnavailable(): JsonResponse
    {
        return $this->make(500, 'scope-unavailable', 'Idempotency scope is unavailable', 'Authenticate the request or configure a custom scope resolver.');
    }

    /** @param array<string, string> $headers */
    private function make(int $status, string $type, string $title, string $detail, ?string $operationId = null, array $headers = []): JsonResponse
    {
        $response = new JsonResponse([
            'type' => 'urn:laravel-idempotency:'.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status, array_merge(['Content-Type' => 'application/problem+json'], $headers));

        if ($operationId !== null) {
            $response->headers->set('Idempotency-Operation-Id', $operationId);
        }

        return $response;
    }
}
