<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Application;

use AmrAchraf\LaravelIdempotencyLedger\Domain\OperationIdentity;
use AmrAchraf\LaravelIdempotencyLedger\Exceptions\IdempotencyConfigurationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final class FingerprintFactory
{
    public function make(Request $request, string $scope, string $key): OperationIdentity
    {
        $secret = $this->secret();
        $method = strtoupper($request->method());
        $route = $this->routeIdentity($request);
        $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type'), 2)[0]));
        $operation = $method."\0".$route;

        return new OperationIdentity(
            id: (string) Str::ulid(),
            scopeHash: $this->hash('scope', $scope, $secret),
            keyHash: $this->hash('key', $key, $secret),
            operationHash: $this->hash('operation', $operation, $secret),
            fingerprintHash: $this->hash('fingerprint', $operation."\0".$contentType."\0".$request->getContent(), $secret),
            claimToken: Str::uuid()->toString(),
        );
    }

    public function keyFrom(Request $request): ?string
    {
        $key = $request->header((string) config('idempotency.header', 'Idempotency-Key'));

        if (! is_string($key) || $key === '') {
            return null;
        }

        if (strlen($key) > 255 || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
            throw new IdempotencyConfigurationException('Idempotency keys must contain 1–255 visible ASCII characters.');
        }

        return $key;
    }

    private function routeIdentity(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route) {
            return $route->getName() ?? $route->uri();
        }

        return $request->path();
    }

    private function hash(string $domain, string $value, string $secret): string
    {
        return hash_hmac('sha256', $domain."\0".$value, $secret);
    }

    private function secret(): string
    {
        $secret = config('idempotency.hash_key');

        if (! is_string($secret) || $secret === '') {
            throw new IdempotencyConfigurationException('Configure IDEMPOTENCY_HASH_KEY or APP_KEY before enabling idempotency.');
        }

        return $secret;
    }
}
