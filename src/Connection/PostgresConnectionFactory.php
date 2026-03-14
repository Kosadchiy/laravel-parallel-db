<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Connection;

use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;

final class PostgresConnectionFactory implements ConnectionFactoryInterface
{
    public function create(array $config): mixed
    {
        if (!function_exists('pg_connect')) {
            throw new ParallelExecutionException('ext-pgsql is required for PostgreSQL async execution.');
        }

        $parts = [];
        $map = [
            'host' => 'host',
            'port' => 'port',
            'database' => 'dbname',
            'username' => 'user',
            'password' => 'password',
            'sslmode' => 'sslmode',
            'application_name' => 'application_name',
        ];

        foreach ($map as $from => $to) {
            $value = $config[$from] ?? null;
            if ($value !== null && $value !== '') {
                $parts[] = sprintf("%s='%s'", $to, str_replace("'", "\\'", (string) $value));
            }
        }

        if (isset($config['options']) && is_string($config['options']) && $config['options'] !== '') {
            $parts[] = sprintf("options='%s'", str_replace("'", "\\'", $config['options']));
        }

        $connString = implode(' ', $parts);
        $connection = $this->capturePgError(
            static fn () => pg_connect($connString, PGSQL_CONNECT_FORCE_NEW),
            'Unable to create PostgreSQL async connection.',
        );

        if (isset($config['charset']) && is_string($config['charset']) && $config['charset'] !== '') {
            $result = $this->capturePgError(
                static fn () => pg_set_client_encoding($connection, $config['charset']),
                'Unable to set PostgreSQL client encoding.',
            );

            if ($result !== 0) {
                throw new ParallelExecutionException('Unable to set PostgreSQL client encoding.');
            }
        }

        return $connection;
    }

    public function close(mixed $connection): void
    {
        if ($connection !== null && function_exists('pg_close')) {
            $this->capturePgClose(static fn () => pg_close($connection));
        }
    }

    private function capturePgError(callable $callback, string $fallbackMessage): mixed
    {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new ParallelExecutionException($warning ?? $fallbackMessage);
        }

        return $result;
    }

    private function capturePgClose(callable $callback): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $callback();
        } finally {
            restore_error_handler();
        }
    }
}
