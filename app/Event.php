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
     * Events the website creates (such as a new drone's starter kit) get the
     * current game time: the engines order and look up events by game_t (#327).
     */
    protected static function booted()
    {
        static::creating(function (Event $event) {
            if ($event->game_t === null) {
                $event->game_t = GameClock::now();
            }
        });
    }

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
