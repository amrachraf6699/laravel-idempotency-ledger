# Laravel Idempotency Ledger

Conservative, database-backed HTTP idempotency for Laravel 10–13 APIs. It prevents duplicate execution for a scoped `Idempotency-Key` and replays supported completed responses.

## Installation

```bash
composer require amrachraf6699/laravel-idempotency-ledger
php artisan vendor:publish --tag=idempotency-config
php artisan vendor:publish --tag=idempotency-migrations
php artisan migrate
```

## Usage

Apply the `idempotent` middleware after authentication:

```php
Route::post('/payments', StorePaymentController::class)
    ->middleware(['auth:sanctum', 'idempotent']);
```

Clients send one random key per logical operation:

```http
Idempotency-Key: 0f8fad5b-d9cb-469f-a165-70867728950e
```

The package scopes keys to the authenticated identity by default. Public and multi-tenant APIs must provide a `ScopeResolver` in `config/idempotency.php`.

## Behavior

- Supported buffered 2xx–4xx responses are stored for 24 hours and replayed with `Idempotency-Replayed: true`.
- Reusing a key with a changed method, route, content type, or body returns `422`.
- A concurrent request returns `409` and `Retry-After: 1`.
- 5xx responses and unexpected exceptions become `indeterminate`; the package will not execute them again automatically.
- Throw `RetryableIdempotencyException` only when no irreversible side effect began. It returns `503`, and the next identical request may claim a new attempt.
- Streams, downloads, binary responses, oversized bodies, and undecryptable stored bodies are completed but non-replayable, so duplicate execution remains blocked.

Response bodies are encrypted using Laravel's encrypter. Set a dedicated, stable `IDEMPOTENCY_HASH_KEY` in production. Do not rotate that key while ledger records may still be queried.

## Cleanup

Schedule `idempotency:prune`. It removes expired completed records and marks overdue processing claims as `indeterminate`; it never retries or removes indeterminate records.

```php
Schedule::command('idempotency:prune')->daily();
```

## Development

```bash
composer test
composer format
composer analyse
```
