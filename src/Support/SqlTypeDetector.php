<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Support;

final class SqlTypeDetector
{
    public static function detect(string $sql): string
    {
        $normalized = strtolower(ltrim($sql));

        return match (true) {
            str_starts_with($normalized, 'select'), str_starts_with($normalized, 'with') => 'select',
            str_starts_with($normalized, 'insert') => 'insert',
            str_starts_with($normalized, 'update') => 'update',
            str_starts_with($normalized, 'delete') => 'delete',
            str_starts_with($normalized, 'create'),
            str_starts_with($normalized, 'alter'),
            str_starts_with($normalized, 'drop'),
            str_starts_with($normalized, 'truncate') => 'ddl',
            default => 'other',
        };
    }
}
