<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property bool $active
 */
final class ParallelTestUser extends Model
{
    /** @var string */
    protected $table = 'parallel_test_users';

    /** @var bool */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'bool',
    ];
}
