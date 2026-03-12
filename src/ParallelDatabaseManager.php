<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb;

use Kosadchiy\LaravelParallelDb\Contracts\ParallelExecutorInterface;
use Kosadchiy\LaravelParallelDb\Contracts\QueryCompilerInterface;
use Kosadchiy\LaravelParallelDb\DTO\ParallelOptions;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;

final readonly class ParallelDatabaseManager
{
    public function __construct(
        private QueryCompilerInterface $compiler,
        private ParallelExecutorInterface $executor,
        private ParallelOptions $options,
    ) {
    }

    /**
     * @param array<string|int, mixed> $queries
     * @return array<string, QueryResult>
     */
    public function run(array $queries): array
    {
        $compiled = $this->compiler->compileMany($queries, $this->options->connection);

        return $this->executor->execute($compiled, $this->options);
    }

    public function withOptions(
        ?int $maxConcurrency = null,
        ?int $timeoutMs = null,
        ?ErrorMode $errorMode = null,
        ?string $connection = null,
    ): self {
        return new self(
            compiler: $this->compiler,
            executor: $this->executor,
            options: new ParallelOptions(
                maxConcurrency: $maxConcurrency ?? $this->options->maxConcurrency,
                timeoutMs: $timeoutMs ?? $this->options->timeoutMs,
                errorMode: $errorMode ?? $this->options->errorMode,
                connection: $connection ?? $this->options->connection,
            ),
        );
    }
}
