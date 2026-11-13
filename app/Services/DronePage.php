<?php

namespace App\Services;

use App\Agent;
use Illuminate\Support\Facades\DB;

/**
 * What the Drone page shows (#324 step 16): the drone's live state, its
 * line (parent and clones), where its code comes from, and for a wreck a
 * post-mortem: what killed it, in plain words, and its last actions.
 */
class DronePage
{
    public const FUEL = 2061;

    /** Moving plunder out of a wreck: done to the wreck after it died, not by it */
    public const PLUNDER_HAUL = 35;

    /** What perform leaves, in plain words */
    public const OUTCOMES = [
        -1 => 'the other drone was somewhere else',
        -2 => 'not enough of a resource (or fuel) in the hold',
        -3 => 'no room in the hold',
        -4 => 'not enough credits',
        -5 => "the seller's order didn't allow selling that many",
        -6 => "the buyer's order didn't allow buying that many",
        -7 => 'wrong price',
        -8 => 'wrong price',
        -9 => 'it was stopped',
        -10 => 'the other drone had no matching order',
        -13 => "the other drone belongs to one of the same player's companies",
        -15 => 'no such wreck here (or already plundered)',
    ];

    /** FORTH error codes a crash can leave, in plain words */
    public const ERRORS = [
        -1 => 'it stopped itself (abort)',
        -3 => 'stack overflow',
        -4 => 'stack underflow (a word took more off the stack than was there)',
        -9 => 'invalid memory address',
        -10 => 'division by zero',
        -13 => 'an undefined word',
        -14 => 'a compile-only word used outside a definition',
    ];

    /** A game time as "day N, hh:mm" (day 0 is the world's first) */
    public static function when(?int $gameT): string
    {
        if (!$gameT) {
            return '';
        }
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $since = max(0, $gameT - $epoch);
        return sprintf('day %d, %02d:%02d', intdiv($since, 86400), intdiv($since % 86400, 3600), intdiv($since % 3600, 60));
    }

    public static function protocolName(int $id): string
    {
        return (string) (DB::table('protocols')->where('id', $id)->value('name') ?? "protocol $id");
    }

    /**
     * Where a flying drone is going (round 31: its place stays the one it
     * left, so it looked parked): the pending move of the action it waits
     * on. Null unless it is flying.
     */
    public static function flight(Agent $agent): ?array
    {
        if ($agent->wrecked_at) {
            return null;
        }
        // Its arrival still to come, whatever it is waiting for now (#380 round
        // 64: a drone whose script carried on during the trip showed where it
        // left from); an aborted action's never comes (#612)
        $move = DB::table('events AS e')->where('e.drone', $agent->agent_id)->where('e.event_type', 3)
            ->whereNotNull('e.new_location')->where('e.game_t', '>', World::gameNow())
            ->whereNotExists(fn ($q) => $q->from('actions AS x')->whereColumn('x.id', 'e.action')->where('x.aborted', 1))
            ->orderBy('e.game_t', 'desc')->first(['e.new_location', 'e.game_t']);
        if (!$move) {
            return null;
        }
        return ['to_id' => (int) $move->new_location,
            'to' => (string) (DB::table('objects')->where('object_id', $move->new_location)->value('object_name') ?? 'object ' . $move->new_location),
            'arrives_game_time' => (int) $move->game_t, 'arrives' => self::when((int) $move->game_t)];
    }

    /**
     * SQL: whether the drone ($drone, an SQL expression) is flying at game
     * time $now: an arrival of its still to come (#380 round 64)
     */
    public static function flyingSql(string $drone, string $now): string
    {
        return "EXISTS (SELECT 1 FROM events AS fm FORCE INDEX (events_drone_game_t) WHERE fm.drone = $drone "
            . "AND fm.game_t > $now AND fm.event_type = 3 AND fm.new_location IS NOT NULL "
            . "AND NOT EXISTS (SELECT 1 FROM actions AS fx WHERE fx.id = fm.action AND fx.aborted = 1))";
    }

    /** What the drone is doing now */
    public static function status(Agent $agent): string
    {
        if ($agent->wrecked_at) {
            return 'a wreck';
        }
        // In flight, say so first (#380 round 64: "waiting for its action to
        // finish: Activate Engine I" told nobody where it was going)
        if ($flight = self::flight($agent)) {
            return 'flying to ' . $flight['to'] . ', arriving ' . $flight['arrives'];
        }
        if ($agent->waiting_action) {
            $protocol = DB::table('actions')->where('id', $agent->waiting_action)->value('protocol');
            return 'waiting for its action to finish: ' . ($protocol ? self::protocolName((int) $protocol) : 'an action');
        }
        // Not launched yet: its script is still arriving or waiting to load
        // (#380 round 35: "running its script" with 0 instructions looked
        // like a hung script while the engine was stopped)
        if (!$agent->launched_running && $agent->release_vm === null
            && DB::table('commands')->where('agent_id', $agent->agent_id)->where('code', '<launched>')->exists()) {
            $pieces = DB::table('commands')->where('agent_id', $agent->agent_id)->where('code', '<>', '<launched>')->count();
            return $pieces ? "loading its script ($pieces " . ($pieces == 1 ? 'piece' : 'pieces') . ' to go)'
                           : 'about to launch';
        }
        if (DB::table('commands')->where('agent_id', $agent->agent_id)->exists()) {
            return 'running its script';
        }
        return $agent->launched_running ? 'running its launched function' : 'idle';
    }

    public static function state(Agent $agent): array
    {
        $company = DB::table('companies')->where('company_id', $agent->company_id)->first();
        return [
            'status' => self::status($agent),
            'fuel' => (int) (DB::table('sqInventory')->where('drone', $agent->agent_id)->where('resource', self::FUEL)->max('units') ?? 0),
            'company' => $company,
            'parent' => $agent->parent_id ? Agent::where('agent_id', $agent->parent_id)->first() : null,
            'clones' => Agent::where('parent_id', $agent->agent_id)->orderBy('agent_id')->get(),
            'generation' => (int) $agent->generation,
            'instructions' => (int) $agent->instructions,
            'crashes' => (int) $agent->crashes_total,
            'fuel_day' => self::fuelLastDay($agent),
        ];
    }

    /**
     * The fuel the drone used in the last game day (#380 round 36: Sven found
     * his drone's thinking cost a fuel a round only from test events): for
     * its actions and for thinking (its computer's power, protocol 2)
     */
    public static function fuelLastDay(Agent $agent): array
    {
        $now = World::gameNow();
        // Each drop in the tank, by the action that made it (#494: which
        // actions a drone's fuel went on)
        $rows = DB::select(
            'SELECT x.protocol, SUM(x.prev - x.q) AS used, COUNT(*) AS times FROM (
                 SELECT e.game_t, e.new_quantity AS q, LAG(e.new_quantity) OVER (ORDER BY e.id) AS prev, a.protocol
                 FROM events e LEFT JOIN actions a ON a.id = e.action
                 WHERE e.drone = ? AND e.resource = ? AND e.outcome = 1 AND e.event_type IN (1, 2)
                   AND e.installed = 0 AND e.locked = 0 AND e.game_t > ?) x
             WHERE x.game_t > ? AND x.game_t <= ? AND x.prev > x.q GROUP BY x.protocol ORDER BY used DESC',
            [$agent->agent_id, self::FUEL, $now - 2 * 86400, $now - 86400, $now]);
        $used = ['actions' => 0, 'thinking' => 0, 'by_action' => []];
        foreach ($rows as $r) {
            $protocol = (int) $r->protocol;
            $used[$protocol === 2 ? 'thinking' : 'actions'] += (int) $r->used;
            $used['by_action'][] = ['what' => $protocol === 2 ? 'Thinking' : self::protocolName($protocol),
                'fuel' => (int) $r->used, 'times' => (int) $r->times];
        }
        return $used;
    }

    /** Where the drone's code comes from, and its words */
    public static function code(Agent $agent): ?array
    {
        $release = Provenance::releaseOf((int) $agent->agent_id);
        if (!$release) {
            return null;
        }
        $sameAs = $release->same_as ? DB::table('releases')->where('id', $release->same_as)->value('agent_id') : null;
        return [
            'release' => $release,
            'inherited' => (int) $release->agent_id !== (int) $agent->agent_id,
            'same_as' => $sameAs ? (int) $sameAs : null,
            'words' => Provenance::wordOrigins($release),
        ];
    }

    /**
     * For a wreck: the cause in plain words, and a timeline of its last
     * actions and crashes, newest first
     */
    /**
     * What the drone has said (#367 step 3: public, like its code): its
     * messages that have reached Earth (game time, at the speed of light),
     * newest first
     */
    public static function messages(Agent $agent, int $limit = 20)
    {
        return DB::table('drone_messages')->where('agent_id', $agent->agent_id)
            ->where('game_t', '<=', World::gameNow())
            ->orderBy('id', 'desc')->limit($limit)->get(['subject', 'body', 'game_t']);
    }

    /**
     * The drone's last actions (power bills left out), newest first: when
     * each started, what it was, how it went (or when it will finish) and
     * the fuel left after it. Actions planned for later aren't shown.
     */
    public static function timeline(int $id, int $limit = 10, ?int $before = null): array
    {
        $now = World::gameNow();
        $timeline = [];
        // Older pages: actions before a given one (#494: 10-15 entries
        // couldn't show where a day's fuel went)
        $actions = DB::table('actions as a')->where('a.drone', $id)->where('a.protocol', '<>', 2)
            ->when($before, fn ($q) => $q->where('a.id', '<', $before))
            ->orderBy('a.id', 'desc')->limit($limit + 5)->get();
        foreach ($actions as $action) {
            $span = DB::table('events')->where('action', $action->id)
                ->selectRaw('MIN(game_t) AS start, MAX(game_t) AS end, SUM(outcome = 0) AS pending')->first();
            if (!$span || $span->start === null || (int) $span->start > $now) {
                continue;
            }
            // Inventory events only: a wait's event names fuel too, with 0
            $fuel = DB::table('events')->where('action', $action->id)->where('drone', $id)->where('resource', self::FUEL)
                ->whereIn('event_type', [1, 2])->where('outcome', 1)->orderBy('id', 'desc')->value('new_quantity');
            $failed = $action->aborted || ($action->outcome !== null && (int) $action->outcome < 0);
            if ($failed) {
                $result = 'failed: ' . (self::OUTCOMES[(int) $action->outcome] ?? 'it was stopped');
            } elseif ((int) $span->pending > 0) {
                $result = 'under way, until ' . self::when((int) $span->end);
            } else {
                $result = 'done';
            }
            $timeline[] = [
                'action' => (int) $action->id,
                'game_t' => (int) $span->start,
                'when' => self::when((int) $span->start),
                'what' => self::protocolName((int) $action->protocol),
                'result' => $result,
                'fuel' => $fuel,
            ];
            if (count($timeline) >= $limit) {
                break;
            }
        }
        return $timeline;
    }

    /**
     * What a working drone is doing (#380): its action under way and when
     * it ends, its last actions, and its last sales and purchases with whom.
     * Newcomers couldn't tell a busy drone from a stuck one.
     */
    public static function activity(Agent $agent): ?array
    {
        if ($agent->wrecked_at) {
            return null;
        }
        $id = (int) $agent->agent_id;
        $now = null;
        if ($agent->waiting_action) {
            $action = DB::table('actions')->where('id', $agent->waiting_action)->first();
            $end = DB::table('events')->where('action', $agent->waiting_action)->max('game_t');
            if ($action) {
                $hours = $end ? max(0, ((int) $end - World::gameNow()) / 3600) : null;
                $flight = self::flight($agent);
                $now = self::protocolName((int) $action->protocol) . ($flight ? ': flying to ' . $flight['to'] : '')
                    . ($end ? ', until ' . self::when((int) $end) . sprintf(' (%s game hours from now)', number_format($hours, 1)) : '');
            }
        }

        return ['now' => $now, 'timeline' => self::timeline($id, 10), 'deals' => self::deals($id)];
    }

    /**
     * A drone's last trades with other drones (the House's station drones
     * included), newest first. A wreck's too (#494: they showed none)
     */
    public static function deals(int $id, int $limit = 10): array
    {
        $deals = [];
        $trades = DB::table('trades as t')->join('resources as r', 'r.id', '=', 't.resource')
            ->where(fn ($q) => $q->where('t.seller', $id)->orWhere('t.buyer', $id))
            ->orderBy('t.id', 'desc')->limit($limit)->get(['t.seller', 't.buyer', 't.units', 't.credits', 't.tax', 't.game_t', 'r.name']);
        foreach ($trades as $t) {
            $sold = (int) $t->seller === $id;
            $other = Agent::where('agent_id', $sold ? $t->buyer : $t->seller)->first();
            $deals[] = ['game_t' => (int) $t->game_t, 'when' => self::when((int) $t->game_t),
                'what' => ($sold ? 'Sold ' : 'Bought ') . (int) $t->units . ' ' . $t->name,
                'with' => $other ? $other->name : 'drone ' . ($sold ? $t->buyer : $t->seller),
                'credits' => $sold ? self::netOfTax((int) $t->credits, (int) $t->tax) : '-' . number_format((int) $t->credits)];
        }
        usort($deals, fn ($a, $b) => $b['game_t'] <=> $a['game_t']);
        return array_slice($deals, 0, $limit);
    }

    /** What a seller got for a sale: the price less the sales tax it paid (#380) */
    private static function netOfTax(int $paid, int $tax): string
    {
        return '+' . number_format($paid - $tax) . ($tax > 0 ? ' (' . number_format($paid) . ' less ' . number_format($tax) . ' tax)' : '');
    }

    public static function postMortem(Agent $agent): ?array
    {
        if (!$agent->wrecked_at) {
            return null;
        }
        $when = self::when((int) $agent->wrecked_at);
        $id = (int) $agent->agent_id;

        $lastAction = DB::table('actions')->where('drone', $id)->whereNotIn('protocol', [2, self::PLUNDER_HAUL])
            ->orderBy('id', 'desc')->first();
        $last = $lastAction ? ' Its last action was ' . self::protocolName((int) $lastAction->protocol) . '.' : '';
        switch ($agent->wreck_cause) {
            case 'fuel':
                // Its thinking burned fuel until #544; since then only actions do
                $thinkingBurned = DB::table('actions')->where('drone', $id)->where('protocol', 2)->exists();
                $cause = "It ran out of fuel on $when: it had none left for its next action." . $last;
                // How much went on thinking: a loop without a wait burned a tank this way (#380)
                $ran = (int) $agent->instructions;
                if ($thinkingBurned && $ran >= config('forge.power_instructions', 200000)) {
                    $cause .= ' Its thinking burned fuel then: its program ran ' . number_format($ran) . ' instructions in all, about '
                        . number_format(intdiv($ran, config('forge.power_instructions', 200000))) . ' fuel of computing.';
                }
                if ($agent->release_vm === null && $agent->parent_id === null) {
                    $cause .= ' It never started its launched function.';
                }
                break;
            case 'crashes':
                $crash = DB::table('drone_crashes')->where('drone', $id)->orderBy('id', 'desc')->first();
                $error = $crash ? (self::ERRORS[(int) $crash->code] ?? 'error ' . $crash->code) : 'an error';
                $cause = 'Its launched function crashed ' . ($crash ? $crash->in_row : 'too many') . " times in a row, the last time with $error, and it was given up on $when.";
                break;
            case 'tax':
                $bill = DB::table('company_taxes')->where('company_id', $agent->company_id)->where('eliminated', 1)->orderBy('id', 'desc')->first();
                $name = DB::table('companies')->where('company_id', $agent->company_id)->value('name');
                $cause = "Its company, $name, couldn't pay its tax bill on $when"
                    . ($bill ? ' (it had ' . number_format($bill->amount) . ' credits left)' : '')
                    . ', so the company was eliminated and all its drones became wrecks.';
                break;
            case 'dissolved':
                $name = DB::table('companies')->where('company_id', $agent->company_id)->value('name');
                $days = (int) (DB::table('house_rules')->where('id', 1)->value('dissolve_days') ?? 50);
                $cause = "Its company, $name, had no credits for $days game days straight, so it dissolved on $when "
                    . 'and all its drones became wrecks, with what they carried, for anyone to plunder.';
                break;
            default:
                $cause = "It became a wreck on $when.";
        }
        if ($agent->plundered_at) {
            $by = Agent::where('agent_id', $agent->plundered_by)->first();
            $cause .= ' On ' . self::when((int) $agent->plundered_at) . ' it was plundered by ' . ($by ? $by->name : 'another drone') . '.';
        }

        $timeline = self::timeline($id, 25);
        foreach (DB::table('drone_crashes')->where('drone', $id)->orderBy('id', 'desc')->limit(5)->get() as $crash) {
            $timeline[] = [
                'game_t' => (int) $crash->game_t,
                'when' => self::when((int) $crash->game_t),
                'what' => 'Crash',
                'result' => self::ERRORS[(int) $crash->code] ?? 'error ' . $crash->code,
                'fuel' => null,
            ];
        }
        usort($timeline, fn ($a, $b) => $b['game_t'] <=> $a['game_t']);

        return ['cause' => $cause, 'timeline' => $timeline];
    }
}
