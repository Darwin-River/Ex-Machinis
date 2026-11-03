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
    public const FUEL_RESERVE = 500;   // station.fth's reserve

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
        $md5 = md5($script);
        $made = [];
        foreach (DB::table('stations')->orderBy('id')->get(['id', 'name', 'object_id']) as $station) {
            $existing = DB::table('agents')->where('company_id', $companyId)->where('station_id', $station->id)
                ->whereNull('wrecked_at')->first(['agent_id', 'program_md5']);
            if ($existing) {
                if ($existing->program_md5 !== $md5) {
                    self::reprogram((int) $existing->agent_id, $script);
                    $made[$station->name . ' (new program)'] = (int) $existing->agent_id;
                }
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
                DB::table('agents')->where('agent_id', $agent->agent_id)
                    ->update(['station_id' => $station->id, 'program_md5' => md5($script)]);
                $cargo = $agent->addCargo(self::IMPORT_STATION);
                $cargo = $agent->addCargo(self::EXPORT_STATION, 1, $cargo);
                $cargo = $agent->addCargo(Release::FUEL, self::START_FUEL, $cargo);
                self::stockAndPost($agent, $station->id, $station->object_id, $cargo);
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

    /**
     * Gives a running station drone the current program (#380 round 36: its
     * orders moved to HouseMarket, and the old program would go on posting
     * its own): its queued commands go, it starts afresh from a new machine,
     * keeping its hold, and launches again.
     */
    public static function reprogram(int $agentId, string $script): void
    {
        DB::transaction(function () use ($agentId, $script) {
            DB::table('commands')->where('agent_id', $agentId)->delete();
            DB::table('agents')->where('agent_id', $agentId)->update([
                'vm' => null, 'release_vm' => null, 'waiting_action' => null, 'pending_input' => null,
                'launched_running' => 0, 'crashes_in_row' => 0, 'program_md5' => md5($script)]);
            foreach (Release::chunks($script) as $chunk) {
                Command::create(['code' => $chunk, 'agent_id' => $agentId, 'subject' => 'Station',
                    'email_content' => '']);
            }
            Command::create(['code' => '<launched>', 'agent_id' => $agentId, 'subject' => 'Station',
                'email_content' => '']);
        });
    }

    /** Event types of a posted order (protocols 7 and 8) */
    public const SELL_ORDER_EVENT = 4;
    public const BUY_ORDER_EVENT = 5;

    /**
     * Opens the exchange for business at once (#380 round 33): its stock on
     * board (a quarter of a day's supply of what it sells, as its program
     * keeps) and every order up (HouseMarket, which keeps them current).
     */
    public static function stockAndPost(Agent $agent, int $stationId, int $objectId, int $cargo): void
    {
        $goods = DB::table('house_market')->where('station_id', $stationId)->where('from_day', '<=', 0)
            ->where('side', 2)->where('resource', '<>', Release::FUEL)->orderBy('resource')
            ->get(['resource', 'daily_units']);
        foreach ($goods as $g) {
            $cargo = $agent->addCargo((int) $g->resource, max(1, intdiv(max(1, (int) $g->daily_units), 4)), $cargo);
        }
        HouseMarket::post(DB::connection(), (int) $agent->agent_id, $stationId, $objectId);
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
