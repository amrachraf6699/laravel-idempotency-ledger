<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Http;

use AmrAchraf\LaravelIdempotencyLedger\Application\FingerprintFactory;
use AmrAchraf\LaravelIdempotencyLedger\Application\OperationCoordinator;
use AmrAchraf\LaravelIdempotencyLedger\Contracts\ScopeResolver;
use AmrAchraf\LaravelIdempotencyLedger\Domain\OperationState;
use AmrAchraf\LaravelIdempotencyLedger\Exceptions\IdempotencyScopeUnavailableException;
use AmrAchraf\LaravelIdempotencyLedger\Exceptions\RetryableIdempotencyException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyMiddleware
{
    public function __construct(
        private readonly ScopeResolver $scopeResolver,
        private readonly FingerprintFactory $fingerprints,
        private readonly OperationCoordinator $operations,
        private readonly ResponseSerializer $responses,
        private readonly ProblemResponseFactory $problems,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtoupper($request->method()), (array) config('idempotency.methods', []), true)) {
            return $next($request);
        }

        $key = $this->fingerprints->keyFrom($request);

        if ($key === null) {
            return (bool) config('idempotency.required', true) ? $this->problems->missingKey() : $next($request);
        }

        try {
            $scope = $this->scopeResolver->resolve($request);
        } catch (IdempotencyScopeUnavailableException) {
            return $this->problems->scopeUnavailable();
        }

        $identity = $this->fingerprints->make($request, $scope, $key);
        $acquisition = $this->operations->acquire($identity);

        if (! $acquisition->owned) {
            $operation = $acquisition->operation;

            if ($operation === null) {
                return $this->problems->inProgress($identity->id);
            }

            if (! hash_equals($operation->fingerprintHash, $identity->fingerprintHash)) {
                return $this->problems->fingerprintMismatch($operation->id);
            }

            return match ($operation->state) {
                OperationState::Processing => $this->problems->inProgress($operation->id),
                OperationState::Indeterminate => $this->problems->indeterminate($operation->id),
                OperationState::Completed => $this->responses->replay($operation) ?? $this->problems->responseUnavailable($operation->id),
                OperationState::Retryable => $this->problems->inProgress($operation->id),
            };
        }

        try {
            $response = $next($request);
        } catch (RetryableIdempotencyException $exception) {
            $this->operations->retryable($identity);

            return $this->problems->retryableFailure($identity->id, $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->operations->indeterminate($identity, 'unhandled_exception');
            Log::warning('Idempotency operation became indeterminate after an exception.', ['idempotency_operation_id' => $identity->id]);

            throw $exception;
        }

        $response->headers->set('Idempotency-Operation-Id', $identity->id);

        if ($response->headers->get('X-Idempotency-Retryable') === 'true') {
            $this->operations->retryable($identity);

            return $this->problems->retryableFailure($identity->id, 'The application explicitly declared that no irreversible work began.');
        }

        if ($response->getStatusCode() >= 500) {
            $this->operations->indeterminate($identity, 'http_5xx');
            Log::warning('Idempotency operation became indeterminate after a 5xx response.', ['idempotency_operation_id' => $identity->id]);

            return $response;
        }

        try {
            $stored = $this->responses->capture($response);
        } catch (\Throwable) {
            $stored = null;
        }

        $completed = $stored === null
            ? $this->operations->completeUnavailable($identity, 'response_unavailable')
            : $this->operations->complete($identity, $stored);

        if (! $completed) {
            Log::warning('Idempotency operation could not be finalized by its original owner.', ['idempotency_operation_id' => $identity->id]);
        }

        return $response;
    }
}
