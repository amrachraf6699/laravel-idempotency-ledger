<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Tests\Support;

use AmrAchraf\LaravelIdempotencyLedger\Contracts\ScopeResolver;
use Illuminate\Http\Request;

final class HeaderScopeResolver implements ScopeResolver
{
    public function resolve(Request $request): string
    {
        return (string) $request->header('X-Test-Scope', 'user:1');
    }
}
