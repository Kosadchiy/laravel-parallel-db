<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Support;

use Illuminate\Database\Eloquent\Model;

final class TestUser extends Model
{
    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];
}
