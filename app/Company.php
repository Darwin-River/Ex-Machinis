<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    const INITIAL_AGENTS = 3;
    // Define some constants to define the objects ID for drones orbits
    // The 3 initial agents will one in each station orbit
    const EARTH_STATION_OBJECT_ID = 187;
    const MARS_STATION_OBJECT_ID = 188;
    const JUPITER_STATION_OBJECT_ID = 189;


    //
    protected $fillable = [
        'name', 'user_id'
    ];
    public $timestamps = false;

    /**
     * Get the user associated with the company.
     */
    public function user()
    {
        return $this->belongsTo('App\User');
    }

    /**
     * Get the agents associated with the company.
     */
    public function agents()
    {
        return $this->hasMany('App\Agent');
    }

    /**
     * Creates the initial drones for a company.
     * @return array of Agent objects
     *
     */
    public function generateStartingDrones()
    {
        $orbits = [ Company::EARTH_STATION_OBJECT_ID, Company::MARS_STATION_OBJECT_ID, Company::JUPITER_STATION_OBJECT_ID ];
        $companyDrones = [];
        for ($i = 0; $i < Company::INITIAL_AGENTS; $i++) {
            $agent = new Agent();
            $agent->assignRandomName();
            $agent->company_id = $this->id;
            //to be changed
            $agent->coord_x = 0;
            $agent->coord_y = 0;
            $agent->coord_z = 0;
            //set the orbit
            $agent->object_id = $orbits[$i];
            $agent->save();
            $companyDrones[] = $agent;
        }
        return $companyDrones;
    }

}
