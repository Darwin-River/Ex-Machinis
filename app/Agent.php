<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $fillable = [
        'name', 'company_id', "coord_x", "coord_y", "coord_z", "speed_x", "speed_y", "speed_z",
    ];
    public $timestamps = false;

    /**
     * Assigns a random name to the unit
     */
    public function assignRandomName()
    {
        $this->name = null;
        $unique = false;
        while (!$unique)//check that no other unit has that name on the company
        {
            $this->name = "SN" . rand(0, 999999);
            if (Agent::where(['name' => $this->name])->get() != null)
                $unique = true;
        }

    }
}
