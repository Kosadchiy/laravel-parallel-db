<?php

declare(strict_types=1);

return [
    'default_max_concurrency' => 3,
    'default_timeout_ms' => 500,
    'error_mode' => 'fail_fast',

    'drivers' => [
        'pgsql' => [
            'enabled' => true,
        ],
        'mysql' => [
            'enabled' => true,
        ],
    ],
];
