<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{

    protected $fillable = [
        'event_type', 'time', 'action', 'logged', 'outcome', 'drone', 'resource', 'installed', 'locked', 'new_quantity', 'new_cargo',
    ];
    public $timestamps = false;

    /**
     * Get the resource associated with the event
     */
    public function resource()
    {
        return $this->belongsTo('App\Resource', 'resource', 'id');
    }

    /**
     * Get the drone associated with the event
     */
    public function agent()
    {
        return $this->belongsTo('App\Agent', 'drone', 'agent_id');
    }

    /**
     * Returns the object the agent is standing at
     */
    public function agentObject()
    {
        return $this->hasOneThrough('App\SpaceObject', 'App\Agent','agent_id','object_id','drone','object_id');
    }

}
