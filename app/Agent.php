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
     * Get the company associated with the agent.
     */
    public function company()
    {
        return $this->belongsTo('App\Company');
    }

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

    /**
     * Extracts and saves all code between tags <script></script>
     * @param string $text mail body
     *
     */
    public function addCodeFromText($text)
    {
        $commandsCreated = false;
        if (preg_match("'<script>(.*?)</script>' si", $text, $matches)) {
            for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                //create new command for this agent
                $command = new Command();
                $command->code = trim($matches[$i]);
                $command->agent_id = $this->id;
                $command->save();
                $commandsCreated = true;

            }
        }
        return $commandsCreated;
    }
}
