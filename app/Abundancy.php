<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Abundancy extends Model
{
    public $timestamps = false;

    /**
     * Return resource object for this abundancy
     * @return HasOne object
     */
    public function resourceObject()
    {
        return $this->hasOne('App\Resource', 'id', 'resource');
    }
}
