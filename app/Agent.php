<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    //NOTE: Agents are the drones

    protected $fillable = [
        'name', 'user_id', 'object_id',
    ];
    public $timestamps = false;

    /**
     * Get the user associated with the agent.
     */
    public function user()
    {
        return $this->belongsTo('App\User');
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
     * @return boolean
     */
    public function addCodeFromText($text)
    {
        $commandsCreated = false;
        if (preg_match("'<script>(.*?)</script>' si", $text, $matches)) {
            for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                //create new command for this agent
                $command = new Command();
                $command->code = trim($matches[$i]);
                $command->agent_id = $this->agent_id;
                $command->save();
                $commandsCreated = true;
            }
        }
        return $commandsCreated;
    }

    /**
     * Extracts and saves all code between tags <script></script> and also email subject in DB
     * @param string $command_text mail body
     * @param string $subject mail subject
     * @return boolean
     *
     */
    public function insertCommandInfo($command_text, $subject)
    {
        $commandsCreated = false;

        // Find what comes first, the abort or the script
        $pos_abort = strpos($command_text, "<abort>");
        $pos_script = strpos($command_text, "<script>");

        if(($pos_abort === false) || (($pos_script !== false) && ($pos_script < $pos_abort))) {

            if (preg_match("'<script>(.*?)</script>' si", $command_text, $matches)) {
                for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                    //create new command for this agent
                    $command = new Command();
                    $command->code = trim($matches[$i]);
                    if(strlen($command->code) > 0) {
                        $command->agent_id = $this->agent_id;
                        $command->subject = $subject;
                        $command->email_content = $command_text;
                        $command->save();
                        $commandsCreated = true;
                    }
                }
            }
        } else {
            // Abort scenario: 
            if (preg_match("'<abort>' si", $command_text)) { // Treat special scenario: abort command received 
                // abort generates and empty command - this empty command means "abort"
                $command = new Command();
                $command->code = "";
                $command->agent_id = $this->agent_id;
                $command->subject = $subject;
                $command->email_content = $command_text;
                $command->save();
                $commandsCreated = true;
            }
        }

        return $commandsCreated;
    }
}
