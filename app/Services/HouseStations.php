<?php

namespace App\Services;

use App\Agent;
use App\Command;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * House station drones (#380): the House trades as drones, by the same rules
 * as the players (orders, pulls and pushes, the sales tax), from a Drop
 * Station hull at each exchange. Each runs resources/house/station.fth, and
 * carries an Import Station and an Export Station: modules no player can get,
 * which bring goods up from the planet surface and send them down.
 *
 * ensure() makes whatever is missing: the House's account and company, and a
 * station drone at each exchange in the stations table. Run it after the game
 * data loads; running it again changes nothing.
 */
class HouseStations
{
    public const EMAIL = 'house@forge.invalid';
    public const NAME = 'The House';
    public const CREDITS = 1000000000;
    public const HULL = 9001;          // Drop Station
    public const IMPORT_STATION = 5911;
    public const EXPORT_STATION = 5921;
    public const START_FUEL = 1000;

    /** The station drones made, by exchange name */
    public static function ensure(): array
    {
        $userId = DB::table('users')->where('email', self::EMAIL)->value('user_id');
        if ($userId === null) {
            $userId = DB::table('users')->insertGetId(['name' => self::NAME, 'email' => self::EMAIL,
                'password' => bcrypt(bin2hex(random_bytes(16)))]);
        }
        $companyId = DB::table('companies')->where('user_id', $userId)->where('house', 1)->value('company_id');
        if ($companyId === null) {
            $companyId = DB::table('companies')->insertGetId(['user_id' => $userId, 'name' => self::NAME,
                'credits' => self::CREDITS, 'house' => 1]);
        }

        $script = self::script();
        $made = [];
        foreach (DB::table('stations')->orderBy('id')->get(['id', 'name', 'object_id']) as $station) {
            $exists = DB::table('agents')->where('company_id', $companyId)->where('station_id', $station->id)
                ->whereNull('wrecked_at')->exists();
            if ($exists) {
                continue;
            }
            DB::transaction(function () use ($station, $userId, $companyId, $script, &$made) {
                $agent = new Agent();
                $agent->name = $station->name;
                $agent->user_id = $userId;
                $agent->company_id = $companyId;
                $agent->object_id = $station->object_id;
                $agent->hull_type = self::HULL;
                $agent->save();
                $agent->agent_id = $agent->id;
                DB::table('agents')->where('agent_id', $agent->agent_id)->update(['station_id' => $station->id]);
                $cargo = $agent->addCargo(self::IMPORT_STATION);
                $cargo = $agent->addCargo(self::EXPORT_STATION, 1, $cargo);
                $agent->addCargo(Release::FUEL, self::START_FUEL, $cargo);
                foreach (Release::chunks($script) as $chunk) {
                    Command::create(['code' => $chunk, 'agent_id' => $agent->agent_id, 'subject' => 'Station',
                        'email_content' => '']);
                }
                Command::create(['code' => '<launched>', 'agent_id' => $agent->agent_id, 'subject' => 'Station',
                    'email_content' => '']);
                $made[$station->name] = $agent->agent_id;
            });
        }
        return $made;
    }

    /** The station drones' program */
    public static function script(): string
    {
        return str_replace("\r\n", "\n", file_get_contents(resource_path('house/station.fth')));
    }

    /** The House's companies (the leaderboard and the company counts leave them out) */
    public static function companyIds(): array
    {
        return DB::table('companies')->where('house', 1)->pluck('company_id')->all();
    }
}
