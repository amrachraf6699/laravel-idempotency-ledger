<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Tests\Feature;

use AmrAchraf\LaravelIdempotencyLedger\Application\FingerprintFactory;
use AmrAchraf\LaravelIdempotencyLedger\Contracts\OperationRepository;
use AmrAchraf\LaravelIdempotencyLedger\Exceptions\RetryableIdempotencyException;
use AmrAchraf\LaravelIdempotencyLedger\Tests\Support\HeaderScopeResolver;
use AmrAchraf\LaravelIdempotencyLedger\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IdempotencyMiddlewareTest extends TestCase
{
    private int $orders = 0;

    private bool $retryOnce = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('idempotency.scope_resolver', HeaderScopeResolver::class);

        Route::post('/orders', function () {
            $this->orders++;

            return response()->json(['order' => $this->orders], 201);
        })->middleware('idempotent');

        Route::post('/failing', fn () => response('upstream failed', 500))->middleware('idempotent');

        Route::post('/retryable', function () {
            if ($this->retryOnce) {
                $this->retryOnce = false;

                throw new RetryableIdempotencyException('The remote dependency was not contacted.');
            }

            return response()->json(['ok' => true]);
        })->middleware('idempotent');

        Route::post('/stream', fn () => new StreamedResponse(static function (): void {
            echo 'stream';
        }))->middleware('idempotent');

        Route::post('/headers', function () {
            return response('ok', 200, ['X-Trace-Id' => 'original-trace', 'Cache-Control' => 'private']);
        })->middleware('idempotent');
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->postJson('/orders')->assertStatus(400)->assertJsonPath('type', 'urn:laravel-idempotency:missing-key');
        self::assertSame(0, $this->orders);
    }

    public function test_completed_response_is_replayed_without_reexecuting_the_route(): void
    {
        $first = $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'order-1']);
        $first->assertCreated()->assertJson(['order' => 1]);

        $second = $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'order-1']);
        $second->assertCreated()->assertJson(['order' => 1])->assertHeader('Idempotency-Replayed', 'true');

        self::assertSame(1, $this->orders);
        self::assertStringNotContainsString('"order"', (string) DB::table('idempotency_operations')->value('response_body'));
    }

    public function test_same_key_with_a_different_payload_is_rejected(): void
    {
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'order-2'])->assertCreated();
        $this->postJson('/orders', ['item' => 'pen'], ['Idempotency-Key' => 'order-2'])
            ->assertStatus(422)
            ->assertJsonPath('type', 'urn:laravel-idempotency:fingerprint-mismatch');

        self::assertSame(1, $this->orders);
    }

    public function test_scope_is_part_of_the_operation_identity(): void
    {
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'order-3', 'X-Test-Scope' => 'user:one'])->assertCreated();
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'order-3', 'X-Test-Scope' => 'user:two'])->assertCreated();

        self::assertSame(2, $this->orders);
    }

    public function test_a_5xx_becomes_indeterminate_and_cannot_be_retried(): void
    {
        $this->post('/failing', [], ['Idempotency-Key' => 'failure-1'])->assertStatus(500);
        $this->post('/failing', [], ['Idempotency-Key' => 'failure-1'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'urn:laravel-idempotency:outcome-unknown');
    }

    public function test_explicitly_retryable_failures_allow_one_new_attempt(): void
    {
        $this->post('/retryable', [], ['Idempotency-Key' => 'retry-1'])
            ->assertStatus(503)
            ->assertJsonPath('type', 'urn:laravel-idempotency:retryable-failure');

        $this->postJson('/retryable', [], ['Idempotency-Key' => 'retry-1'])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_streamed_responses_are_completed_without_replay_data(): void
    {
        $this->post('/stream', [], ['Idempotency-Key' => 'stream-1'])->assertOk();
        $this->post('/stream', [], ['Idempotency-Key' => 'stream-1'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'urn:laravel-idempotency:response-unavailable');
    }

    public function test_only_explicitly_allowed_headers_are_replayed(): void
    {
        config()->set('idempotency.replay.additional_headers', ['X-Trace-Id']);

        $this->post('/headers', [], ['Idempotency-Key' => 'headers-1'])->assertOk();
        $this->post('/headers', [], ['Idempotency-Key' => 'headers-1'])
            ->assertOk()
            ->assertHeader('X-Trace-Id', 'original-trace')
            ->assertHeader('Cache-Control', 'private');
    }

    public function test_prune_marks_stale_claims_indeterminate(): void
    {
        $request = Request::create('/stale', 'POST', [], [], [], [], '{"operation":"stale"}');
        $identity = app(FingerprintFactory::class)->make($request, 'user:1', 'stale-1');
        $operations = app(OperationRepository::class);
        $operations->claim($identity, new DateTimeImmutable('-16 minutes'), new DateTimeImmutable('-1 minute'));

        $this->artisan('idempotency:prune')->assertSuccessful();

        self::assertSame('indeterminate', DB::table('idempotency_operations')->where('id', $identity->id)->value('state'));
        self::assertSame('stale_claim', DB::table('idempotency_operations')->where('id', $identity->id)->value('resolution_reason'));
    }
}
