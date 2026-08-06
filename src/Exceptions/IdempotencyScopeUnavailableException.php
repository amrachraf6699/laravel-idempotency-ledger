<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Exceptions;

use RuntimeException;

final class IdempotencyScopeUnavailableException extends RuntimeException {}
