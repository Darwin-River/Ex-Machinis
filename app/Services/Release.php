<?php

namespace App\Services;

use App\Agent;
use App\Command;
use App\Company;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Releasing a script (#324 step 6): a new company with one drone in Earth
 * orbit and a starter kit. The drone is given the script, then runs its
 * launched word (the <launched> meta-command), with crash handling.
 */
class Release
{
    /** A script must define the word launched (case matters) */
    public static function definesLaunched(string $script): bool
    {
        return preg_match('/(^|\s):\s+launched(\s|$)/', $script) === 1;
    }

    /**
     * Splits a script into commands that fit the commands table, at line
     * ends (a word definition may span lines: the VM keeps compiling
     * across commands, as it does across an email's lines)
     */
    public static function chunks(string $script): array
    {
        $chunks = [];
        $current = '';
        foreach (preg_split('/\r\n|\r|\n/', $script) as $line) {
            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (mb_strlen($candidate) > Command::MAX_CODE_LENGTH && $current !== '') {
                $chunks[] = $current;
                $candidate = $line;
            }
            $current = $candidate;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Releases the script as a new company of this player; returns its drone.
     * $connection picks the world: null for the live one, 'testworld' for a
     * test run (#324 step 7).
     */
    public static function release(User $user, string $companyName, string $script, ?string $connection = null): Agent
    {
        if ($connection !== null) {
            // The models use the default connection: point it at that world
            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection($connection);
            try {
                return self::release($user, $companyName, $script);
            } finally {
                DB::setDefaultConnection($previous);
            }
        }

        return DB::transaction(function () use ($user, $companyName, $script) {
            $company = Company::create([
                'user_id' => $user->user_id,
                'name' => $companyName,
                'credits' => config('forge.starting_credits'),
            ]);

            $agent = new Agent();
            $agent->assignRandomName();
            $agent->user_id = $user->user_id;
            $agent->company_id = $company->company_id;
            $agent->object_id = Company::EARTH_OBJECT_ID;
            $agent->hull_type = Company::DEFAULT_DRONE_HULL_TYPE;
            $agent->save();
            $agent->agent_id = $agent->id; // the key the save filled in
            $agent->addCargo(5901); // Starter Kit I

            foreach (self::chunks($script) as $chunk) {
                Command::create([
                    'code' => $chunk,
                    'agent_id' => $agent->agent_id,
                    'subject' => 'Release',
                    'email_content' => '',
                ]);
            }
            Command::create([
                'code' => '<launched>',
                'agent_id' => $agent->agent_id,
                'subject' => 'Release',
                'email_content' => '',
            ]);

            return $agent;
        });
    }
}
