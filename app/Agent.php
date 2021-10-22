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
        return $this->belongsTo('App\User', 'user_id', 'user_id');
    }

    /**
     * Get the hull associated with the agent.
     */
    public function hull()
    {
        return $this->belongsTo('App\Hull', 'hull_type', 'id');
    }

    /**
     * Get the last 20 events occurred to the agent, with the last on top
     */
    public function lastEVents()
    {
        return $this->hasMany('App\Event', 'drone', 'agent_id')->orderBy('id', 'desc')->take(10);
    }

    /**
     * Get the agent's current location
     */
    public function location()
    {
        return $this->belongsTo('App\SpaceObject', 'object_id', 'object_id');
    }

    /**
     * Get the agent's resources inventory
     */
    public function inventory()
    {
        return $this->hasOne('App\Inventory', 'drone', 'agent_id');
    }

    /**
     * Get the agent's resources inventory
     */
    public function object()
    {
        return $this->belongsTo('App\SpaceObject', 'object_id', 'object_id');
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

    public function addCargo($cargo)
    {
      $event = new Event();
      $event->event_type = 1;
      $event->action = 0;
      $event->logged = true;
      $event->outcome = 1;
      $event->drone = $this->id;
      $event->resource = $cargo;
      $event->installed = false;
      $event->locked = false;
      $event->new_quantity = 1;
      $event->new_cargo = 10;
      $event->save();
    }
    /**
     * Extracts and saves all code between tags <run></run>
     * @param string $text mail body
     * @return boolean
     */
    public function addCodeFromText($text)
    {
        $commandsCreated = false;
        if (preg_match("'<run>(.*?)</run>' si", $text, $matches)) {
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
     * Process emails received with <run> meta-command
     * @param string $command_text mail body
     * @param string $subject mail subject
     * @return boolean Flag to indicate if the command was inserted in DB
     *
     */
    private function processRunCommand($command_text, $subject)
    {
        if (preg_match("'<run>(.*?)</run>' si", $command_text, $matches)) {
            for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                //create new command for this agent
                $command = new Command();
                $command->code = trim($matches[$i]);
                if (strlen($command->code) > 0) {
                    $command->agent_id = $this->agent_id;
                    $command->subject = $subject;
                    $command->email_content = $command_text;
                    $command->save();
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Process emails received with <abort> meta-command. We can receive a single abort of abort + command
     * Deal with both scenarios:
     *
     * - <abort>
     * - <abort>commands</abort>
     *
     * @param string $command_text mail body
     * @param string $subject mail subject
     * @return boolean Flag to indicate if the command was inserted in DB
     *
     */
    private function processAbortCommand($command_text, $subject)
    {
        $result = false;

        // Abort scenario (only abort)
        if (preg_match("'<abort>' si", $command_text)) { // Treat special scenario: abort command received
            // abort generates and empty command - this empty command means "abort"
            $command = new Command();
            $command->code = "<abort>";
            $command->agent_id = $this->agent_id;
            $command->subject = $subject;
            $command->email_content = $command_text;
            $command->save();
            $result = true;
        }

        // Check if there is a command to be executed
        if (preg_match("'<abort>(.*?)</abort>' si", $command_text, $matches)) {
            // Execute embedded command now
            for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                //create new command for this agent
                $command = new Command();
                $command->code = trim($matches[$i]);
                if (strlen($command->code) > 0) {
                    $command->agent_id = $this->agent_id;
                    $command->subject = $subject;
                    $command->email_content = $command_text;
                    $command->save();
                    $result = true;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Process emails received with <reset> meta-command. We can receive a single reset of reset + command
     * Deal with both scenarios:
     *
     * - <reset>
     * - <reset>commands</reset>
     *
     * @param string $command_text mail body
     * @param string $subject mail subject
     * @return boolean Flag to indicate if the command was inserted in DB
     *
     */
    private function processResetCommand($command_text, $subject)
    {
        $result = false;

        // Reset scenario:
        if (preg_match("'<reset>' si", $command_text)) {
            // reset generates and empty command - this empty command means "reset"
            $command = new Command();
            $command->code = "<reset>";
            $command->agent_id = $this->agent_id;
            $command->subject = $subject;
            $command->email_content = $command_text;
            $command->save();
            $result = true;
        }

        // Check if there is a command to be executed
        if (preg_match("'<reset>(.*?)</reset>' si", $command_text, $matches)) {
            // Execute embedded command now
            for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                //create new command for this agent
                $command = new Command();
                $command->code = trim($matches[$i]);
                if (strlen($command->code) > 0) {
                    $command->agent_id = $this->agent_id;
                    $command->subject = $subject;
                    $command->email_content = $command_text;
                    $command->save();
                    $result = true;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Process emails received with <rename> meta-command
     * @param string $command_text mail body
     * @param string $subject mail subject
     * @return boolean Flag to indicate if the command was inserted in DB
     *
     */
    private function processRenameCommand($command_text, $subject)
    {
        if (preg_match("'<rename>(.*?)</rename>' si", $command_text, $matches)) {
            for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                //create new command for this agent
                $command = new Command();
                $new_name = trim($matches[$i]);
                $command->code = '<rename>' . $new_name . '</rename>';
                if (strlen($command->code) > 0) {
                    $command->agent_id = $this->agent_id;
                    $command->subject = $subject;
                    $command->email_content = $command_text;
                    $command->save();
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Process emails received with <rebrand> meta-command
     * @param string $command_text mail body
     * @param string $subject mail subject
     * @return boolean Flag to indicate if the command was inserted in DB
     *
     */
    private function processRebrandCommand($command_text, $subject)
    {
        if (preg_match("'<rebrand>(.*?)</rebrand>' si", $command_text, $matches)) {
            for ($i = 1; $i < sizeof($matches); $i = $i + 2) {
                //create new command for this agent
                $command = new Command();
                $command->code = '<rebrand>' . trim($matches[$i]) . '</rebrand>';
                if (strlen($command->code) > 0) {
                    $command->agent_id = $this->agent_id;
                    $command->subject = $subject;
                    $command->email_content = $command_text;
                    $command->save();
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extracts and saves all code between tags <run></run> and also email subject in DB
     * @param string $command_text mail body
     * @param string $subject mail subject
     * @return boolean
     *
     */
    public function insertCommandInfo($command_text, $subject)
    {
        $commandsCreated = false;

        // Define an array where we will store the position of all the meta-commands found
        $positions_array = [];

        // Find the position of the supported meta-commands
        // Insert positions found into array
        $pos_abort = strpos($command_text, "<abort>");
        if ($pos_abort !== false) {
            $positions_array["abort"] = $pos_abort;
        }

        $pos_run = strpos($command_text, "<run>");
        if ($pos_run !== false) {
            $positions_array["run"] = $pos_run;
        }

        $pos_reset = strpos($command_text, "<reset>");
        if ($pos_reset !== false) {
            $positions_array["reset"] = $pos_reset;
        }

        $pos_rename = strpos($command_text, "<rename>");
        if ($pos_rename !== false) {
            $positions_array["rename"] = $pos_rename;
        }

        $pos_rebrand = strpos($command_text, "<rebrand>");
        if ($pos_rebrand !== false) {
            $positions_array["rebrand"] = $pos_rebrand;
        }

        // Check if any was found
        if (empty($positions_array))
            return false;

        // Find the first one
        $first = array_search(min($positions_array), $positions_array);

        // Create the suitable command depending on meta-command received
        switch ($first) {
            case "run":
                $commandsCreated = $this->processRunCommand($command_text, $subject);
                break;
            case "abort":
                $commandsCreated = $this->processAbortCommand($command_text, $subject);
                break;
            case "reset":
                $commandsCreated = $this->processResetCommand($command_text, $subject);
                break;
            case "rename":
                $commandsCreated = $this->processRenameCommand($command_text, $subject);
                break;
            case "rebrand":
                $commandsCreated = $this->processRebrandCommand($command_text, $subject);
                break;
            default:
                // Do nothing
                break;
        }

        return $commandsCreated;
    }

    /**
     * Returns weight of current ship's cargo
     * @return integer (weight in kg.)
     */
    public function getCurrentCargoWeight()
    {
        $lastInventoryEvent = Event::where('drone', '=', $this->agent_id)->where('outcome', '=', 1)->where('new_cargo', '>=', 0)->where(function ($q) {
            $q->where('event_type', EventType::TYPE_DECREMENT_INVENTORY)
                ->orWhere('event_type', EventType::TYPE_INCREMENT_INVENTORY);
        })->orderBy('id', 'desc')->first();
       /* echo  Event::where('drone', '=', $this->agent_id)->where('outcome', '=', 1)->where('new_cargo', '>=', 0)->where(function ($q) {
                $q->where('event_type', EventType::TYPE_DECREMENT_INVENTORY)
                    ->orWhere('event_type', EventType::TYPE_INCREMENT_INVENTORY);
            })->orderBy('id', 'desc')->toSql();*/
        if (!$lastInventoryEvent)
            return 0;
        return $lastInventoryEvent->new_cargo === null ? 0 : max(0, $lastInventoryEvent->new_cargo);
    }
}
