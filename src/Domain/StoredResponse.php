<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Domain;

final readonly class StoredResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        public ?string $contentType,
        public array $headers,
        public string $body,
        public int $bodySize,
        public bool $bodyEncrypted,
    ) {}
}
