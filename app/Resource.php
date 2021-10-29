<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    public $timestamps = false;

    /**
     * Get resource mass
     */
    public function mass()
    {
        return $this->mass;
    }
}
