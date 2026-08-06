<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('idempotency.connection');
        $tableName = config('idempotency.table', 'idempotency_operations');

        Schema::connection($connection)->create($tableName, function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('scope_hash', 64);
            $table->char('key_hash', 64);
            $table->char('operation_hash', 64);
            $table->char('fingerprint_hash', 64);
            $table->string('state', 16);
            $table->uuid('claim_token')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('stale_after_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_content_type')->nullable();
            $table->json('response_headers')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->unsignedInteger('response_body_size')->nullable();
            $table->boolean('response_body_encrypted')->default(false);
            $table->boolean('replayable')->default(false);
            $table->string('resolution_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['scope_hash', 'key_hash'], 'idempotency_operations_scope_key_unique');
            $table->index(['state', 'stale_after_at'], 'idempotency_operations_state_stale_index');
            $table->index(['state', 'expires_at'], 'idempotency_operations_state_expires_index');
        });
    }

    public function down(): void
    {
        Schema::connection(config('idempotency.connection'))
            ->dropIfExists(config('idempotency.table', 'idempotency_operations'));
    }
};
