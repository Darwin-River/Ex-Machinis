<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SpaceObject extends Model
{
    protected $table = 'objects';
    public $timestamps = false;

    /**
     * Return object that is the main part of this
     * @return HasOne object
     */
    public function centralBody()
    {
        return $this->hasOne('App\SpaceObject', 'object_id', 'central_body_object_id');
    }
}
