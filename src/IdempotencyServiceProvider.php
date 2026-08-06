<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger;

use AmrAchraf\LaravelIdempotencyLedger\Application\FingerprintFactory;
use AmrAchraf\LaravelIdempotencyLedger\Application\OperationCoordinator;
use AmrAchraf\LaravelIdempotencyLedger\Console\PruneIdempotencyOperations;
use AmrAchraf\LaravelIdempotencyLedger\Contracts\OperationRepository;
use AmrAchraf\LaravelIdempotencyLedger\Contracts\ScopeResolver;
use AmrAchraf\LaravelIdempotencyLedger\Http\IdempotencyMiddleware;
use AmrAchraf\LaravelIdempotencyLedger\Http\ProblemResponseFactory;
use AmrAchraf\LaravelIdempotencyLedger\Http\ResponseSerializer;
use AmrAchraf\LaravelIdempotencyLedger\Infrastructure\Database\DatabaseOperationRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/idempotency.php', 'idempotency');

        $this->app->singleton(OperationRepository::class, DatabaseOperationRepository::class);
        $this->app->singleton(FingerprintFactory::class);
        $this->app->singleton(OperationCoordinator::class);
        $this->app->singleton(ProblemResponseFactory::class);
        $this->app->singleton(ResponseSerializer::class, fn ($app): ResponseSerializer => new ResponseSerializer($app->make(Encrypter::class)));
        $this->app->bind(ScopeResolver::class, fn ($app): ScopeResolver => $app->make(config('idempotency.scope_resolver')));
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('idempotent', IdempotencyMiddleware::class);

        $this->publishes([
            __DIR__.'/../config/idempotency.php' => config_path('idempotency.php'),
        ], 'idempotency-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_idempotency_operations_table.php' => database_path('migrations/create_idempotency_operations_table.php'),
        ], 'idempotency-migrations');

        $this->commands([PruneIdempotencyOperations::class]);
    }
}
