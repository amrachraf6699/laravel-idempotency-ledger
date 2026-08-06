<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Console;

use AmrAchraf\LaravelIdempotencyLedger\Contracts\OperationRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PruneIdempotencyOperations extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Prune expired completed idempotency operations and mark stale processing operations indeterminate.';

    public function handle(OperationRepository $operations): int
    {
        $result = $operations->prune(now());

        if ($result['stale'] > 0) {
            Log::warning('Stale idempotency operations were marked indeterminate.', ['count' => $result['stale']]);
        }

        $this->components->info("Marked {$result['stale']} stale operation(s); pruned {$result['pruned']} completed operation(s).");

        return self::SUCCESS;
    }
}
