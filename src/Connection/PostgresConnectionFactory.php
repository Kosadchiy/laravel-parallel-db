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

        $connection = @pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);

        if ($connection === false) {
            throw new ParallelExecutionException('Unable to create PostgreSQL async connection.');
        }

        if (isset($config['charset']) && is_string($config['charset']) && $config['charset'] !== '') {
            @pg_set_client_encoding($connection, $config['charset']);
        }

        return $connection;
    }

    public function close(mixed $connection): void
    {
        if ($connection !== null && function_exists('pg_close')) {
            @pg_close($connection);
        }
    }
}
