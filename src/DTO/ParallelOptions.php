<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;

final readonly class ParallelOptions
{
    public function __construct(
        public int $maxConcurrency = 3,
        public int $timeoutMs = 500,
        public ErrorMode $errorMode = ErrorMode::FAIL_FAST,
        public ?string $connection = null,
    ) {
    }
}
