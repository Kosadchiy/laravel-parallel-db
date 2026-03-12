<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb;

use Kosadchiy\LaravelParallelDb\Contracts\ParallelExecutorInterface;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\ParallelOptions;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\DTO\RunningQuery;
use Kosadchiy\LaravelParallelDb\Driver\DriverRegistry;
use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryFailedException;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryTimeoutException;

final readonly class ParallelExecutor implements ParallelExecutorInterface
{
    public function __construct(private DriverRegistry $drivers)
    {
    }

    public function execute(array $queries, ParallelOptions $options): array
    {
        if ($queries === []) {
            return [];
        }

        $maxConcurrency = max(1, $options->maxConcurrency);
        $deadline = microtime(true) + ($options->timeoutMs / 1000);
        $pending = array_values($queries);

        /** @var array<string, RunningQuery> $running */
        $running = [];
        /** @var array<string, QueryResult> $results */
        $results = [];

        try {
            while ($pending !== [] || $running !== []) {
                while (count($running) < $maxConcurrency && $pending !== []) {
                    /** @var CompiledQuery $next */
                    $next = array_shift($pending);
                    $driver = $this->drivers->get($next->driver);
                    $running[$next->key] = $driver->start($next);
                }

                if ($running === []) {
                    continue;
                }

                if (microtime(true) > $deadline) {
                    throw new ParallelQueryTimeoutException('Parallel query batch timed out.');
                }

                $remainingMs = max(1, (int) (($deadline - microtime(true)) * 1000));
                $tickMs = min(25, $remainingMs);
                $readyKeys = $this->pollReady($running, $tickMs);

                if ($readyKeys === []) {
                    continue;
                }

                foreach ($readyKeys as $key) {
                    if (!isset($running[$key])) {
                        continue;
                    }

                    $runningQuery = $running[$key];
                    $driver = $this->drivers->get($runningQuery->driver);

                    try {
                        $result = $driver->collect($runningQuery);

                        if (!$result->success && $options->errorMode === ErrorMode::FAIL_FAST) {
                            throw new ParallelQueryFailedException($runningQuery->query, $result->error ?? 'Unknown query error');
                        }

                        $results[$key] = $result;
                    } finally {
                        $driver->close($runningQuery);
                        unset($running[$key]);
                    }
                }
            }
        } finally {
            foreach ($running as $runningQuery) {
                $this->drivers->get($runningQuery->driver)->close($runningQuery);
            }
        }

        return $results;
    }

    /**
     * @param array<string, RunningQuery> $running
     * @return array<int, string>
     */
    private function pollReady(array $running, int $tickMs): array
    {
        $byDriver = [];
        foreach ($running as $key => $runningQuery) {
            $byDriver[$runningQuery->driver][$key] = $runningQuery;
        }

        if (count($byDriver) === 1) {
            $driverName = array_key_first($byDriver);

            return $this->drivers->get($driverName)->poll($byDriver[$driverName], $tickMs);
        }

        $ready = [];

        foreach ($byDriver as $driverName => $driverRunning) {
            $ready = [...$ready, ...$this->drivers->get($driverName)->poll($driverRunning, 0)];
        }

        if ($ready === []) {
            usleep($tickMs * 1000);
        }

        return array_values(array_unique($ready));
    }
}
