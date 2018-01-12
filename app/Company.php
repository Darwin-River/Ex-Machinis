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
        $companyDrones = [];
        for ($i = 0; $i < Company::INITIAL_AGENTS; $i++) {
            $agent = new Agent();
            $agent->assignRandomName();
            $agent->company_id = $this->id;
            //to be changed
            $agent->coord_x = 0;
            $agent->coord_y = 0;
            $agent->coord_z = 0;
            $agent->save();
            $companyDrones[] = $agent;
        }
        return $companyDrones;
    }

}
