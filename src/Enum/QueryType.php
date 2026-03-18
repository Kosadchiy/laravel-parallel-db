<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Enum;

enum QueryType: string
{
    case SELECT = 'select';
    case INSERT = 'insert';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case DDL = 'ddl';
    case OTHER = 'other';
}
