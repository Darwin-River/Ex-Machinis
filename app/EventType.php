<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    public $timestamps = false;

    const TYPE_INCREMENT_INVENTORY = 1;
    const TYPE_DECREMENT_INVENTORY = 2;
    const TYPE_MOVE_TO_LOCATION = 3;
    const SELL_RESOURCE = 4;
    const BUY_RESOURCE = 5;
}
