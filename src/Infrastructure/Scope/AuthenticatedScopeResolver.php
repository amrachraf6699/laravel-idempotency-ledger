<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Infrastructure\Scope;

use AmrAchraf\LaravelIdempotencyLedger\Contracts\ScopeResolver;
use AmrAchraf\LaravelIdempotencyLedger\Exceptions\IdempotencyScopeUnavailableException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final class AuthenticatedScopeResolver implements ScopeResolver
{
    public function resolve(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable || $user->getAuthIdentifier() === null) {
            throw new IdempotencyScopeUnavailableException(
                'The idempotent middleware requires an authenticated principal or a custom scope resolver.',
            );
        }

        return sprintf('auth:%s', (string) $user->getAuthIdentifier());
    }
}
