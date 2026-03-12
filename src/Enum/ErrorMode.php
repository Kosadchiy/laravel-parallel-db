<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Enum;

enum ErrorMode: string
{
    case FAIL_FAST = 'fail_fast';
    case COLLECT = 'collect';
}
