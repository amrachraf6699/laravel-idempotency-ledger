<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Contracts;

use Illuminate\Http\Request;

interface ScopeResolver
{
    /**
     * Return a stable, application-defined identity for the requester.
     */
    public function resolve(Request $request): string;
}
