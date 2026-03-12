<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Exceptions;

use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;

final class ParallelQueryFailedException extends ParallelExecutionException
{
    public function __construct(
        public readonly CompiledQuery $query,
        string $message,
    ) {
        parent::__construct($message);
    }
}
