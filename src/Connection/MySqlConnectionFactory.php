<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Connection;

use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use mysqli;

final class MySqlConnectionFactory implements ConnectionFactoryInterface
{
    public function create(array $config): mixed
    {
        if (!class_exists(mysqli::class)) {
            throw new ParallelExecutionException('ext-mysqli is required for MySQL async execution.');
        }

        $connection = mysqli_init();

        if ($connection === false) {
            throw new ParallelExecutionException('Unable to initialize MySQLi connection.');
        }

        if (isset($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as $option => $value) {
                $connection->options((int) $option, $value);
            }
        }

        $connected = @$connection->real_connect(
            hostname: (string) ($config['host'] ?? '127.0.0.1'),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            database: (string) ($config['database'] ?? ''),
            port: (int) ($config['port'] ?? 3306),
            socket: isset($config['unix_socket']) ? (string) $config['unix_socket'] : null,
        );

        if ($connected !== true) {
            throw new ParallelExecutionException('Unable to create MySQL async connection: ' . $connection->connect_error);
        }

        $charset = is_string($config['charset'] ?? null) ? $config['charset'] : 'utf8mb4';
        $connection->set_charset($charset);

        return $connection;
    }

    public function close(mixed $connection): void
    {
        if ($connection instanceof mysqli) {
            @$connection->close();
        }
    }
}
