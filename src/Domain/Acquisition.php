<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Domain;

final readonly class Acquisition
{
    private function __construct(public bool $owned, public ?Operation $operation) {}

    public static function owned(): self
    {
        return new self(true, null);
    }

    public static function existing(Operation $operation): self
    {
        return new self(false, $operation);
    }
}
