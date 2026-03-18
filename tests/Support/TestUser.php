<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
final class TestUser extends Model
{
    /** @var string */
    protected $table = 'users';

    /** @var bool */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

}
