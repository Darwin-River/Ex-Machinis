<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    const INITIAL_AGENTS = 3;
    //
    protected $fillable = [
        'name', 'user_id'
    ];
    public $timestamps = false;

}
