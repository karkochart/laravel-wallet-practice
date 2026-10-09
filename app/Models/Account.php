<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];
    // balance приходит из PG строкой (NUMERIC) - считаем через bcmath, не через (int)/float.
}
