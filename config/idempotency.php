<?php

declare(strict_types=1);

use AmrAchraf\LaravelIdempotencyLedger\Infrastructure\Scope\AuthenticatedScopeResolver;

return [
    /*
    |--------------------------------------------------------------------------
    | Request Header
    |--------------------------------------------------------------------------
    |
    | Clients must send this header once for each logical operation, then reuse
    | the same value only when retrying that exact operation. Values must be
    | visible ASCII and no more than 255 characters long.
    |
    */
    'header' => 'Idempotency-Key',

    /*
    |--------------------------------------------------------------------------
    | Require a Key
    |--------------------------------------------------------------------------
    |
    | When true, protected mutating requests without the configured header are
    | rejected with a 400 problem response. Set this to false only while rolling
    | out client support; keyless requests then pass through unchanged.
    |
    */
    'required' => true,

    /*
    |--------------------------------------------------------------------------
    | Protected HTTP Methods
    |--------------------------------------------------------------------------
    |
    | The middleware is a no-op for methods outside this list. POST, PUT, PATCH,
    | and DELETE are protected by default because they commonly perform writes.
    | Apply the middleware only to routes whose operations are safe to ledger.
    |
    */
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    /*
    |--------------------------------------------------------------------------
    | Ledger Database Connection and Table
    |--------------------------------------------------------------------------
    |
    | Null uses Laravel's default database connection. Configure a dedicated
    | connection when idempotency data belongs in a different database. The
    | published migration reads these values when it is executed, so publish and
    | configure them before migrating.
    |
    */
    'connection' => null,
    'table' => 'idempotency_operations',

    /*
    |--------------------------------------------------------------------------
    | Completion Retention and Stale Claims
    |--------------------------------------------------------------------------
    |
    | ttl is the number of seconds a completed operation remains replayable.
    | Once it is pruned, its key can begin a new operation. stale_after is the
    | maximum time a claim may remain processing. Stale claims become
    | indeterminate, never automatically retryable, because their effects may
    | already have occurred. Both values must be positive integers.
    |
    */
    'ttl' => 86_400,
    'stale_after' => 900,

    /*
    |--------------------------------------------------------------------------
    | Request Scope Resolver
    |--------------------------------------------------------------------------
    |
    | Keys are unique only within this resolver's returned scope. The default
    | requires an authenticated principal. For public APIs or tenancy, replace
    | it with a ScopeResolver implementation that returns a stable opaque value,
    | for example "tenant:42:user:17". Never use a client IP address as scope.
    |
    */
    'scope_resolver' => AuthenticatedScopeResolver::class,

    /*
    |--------------------------------------------------------------------------
    | HMAC Hash Key
    |--------------------------------------------------------------------------
    |
    | Raw idempotency keys, scopes, and request bodies are never stored. The
    | package uses this secret to derive HMAC-SHA-256 lookup hashes. Set a
    | dedicated IDEMPOTENCY_HASH_KEY in production; APP_KEY is only a convenient
    | fallback. Do not rotate the effective key while ledger records are active,
    | because old hashes can no longer be found after rotation.
    |
    */
    'hash_key' => env('IDEMPOTENCY_HASH_KEY', config('app.key')),

    /*
    |--------------------------------------------------------------------------
    | Response Replay
    |--------------------------------------------------------------------------
    |
    | Only buffered UTF-8 responses with a 2xx–4xx status are eligible for
    | replay. Streams, downloads, binary responses, 5xx responses, and bodies
    | larger than max_response_bytes are recorded as non-replayable, preventing
    | duplicate execution without retaining an unsafe response payload.
    |
    */
    'replay' => [
        // Maximum original body size in bytes; encrypted storage may be larger.
        'max_response_bytes' => 65_536,

        // Encrypt stored response bodies with Laravel's encrypter at rest.
        'encrypt_body' => true,

        // Extra response headers to persist. Sensitive and hop-by-hop headers
        // (for example Set-Cookie, Authorization, Content-Length, Date, and
        // Retry-After) are rejected even when listed here.
        'additional_headers' => [],
    ],
];
