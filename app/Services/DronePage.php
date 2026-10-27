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
        -14 => 'no House station here trades that',
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

    /** What the drone is doing now */
    public static function status(Agent $agent): string
    {
        if ($agent->wrecked_at) {
            return 'a wreck';
        }
        if ($agent->waiting_action) {
            $protocol = DB::table('actions')->where('id', $agent->waiting_action)->value('protocol');
            return 'waiting for its action to finish: ' . ($protocol ? self::protocolName((int) $protocol) : 'an action');
        }
        if (DB::table('commands')->where('agent_id', $agent->agent_id)->exists()) {
            return 'running its script';
        }
        return $agent->launched_running ? 'running its launched word' : 'idle';
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
        ];
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
                $cause = "It ran out of fuel on $when: it had none left to pay for its computing "
                    . '(1 fuel for every 100,000 instructions its program runs) or its next action.' . $last;
                // How much went on thinking: a loop without a wait burns a tank this way (#380)
                $ran = (int) $agent->instructions;
                if ($ran >= 100000) {
                    $cause .= ' Its program ran ' . number_format($ran) . ' instructions in all, about '
                        . number_format(intdiv($ran, 100000)) . ' fuel of computing.';
                }
                if ($agent->release_vm === null && $agent->parent_id === null) {
                    $cause .= ' It never started its launched word.';
                }
                break;
            case 'crashes':
                $crash = DB::table('drone_crashes')->where('drone', $id)->orderBy('id', 'desc')->first();
                $error = $crash ? (self::ERRORS[(int) $crash->code] ?? 'error ' . $crash->code) : 'an error';
                $cause = 'Its launched word crashed ' . ($crash ? $crash->in_row : 'too many') . " times in a row, the last time with $error, and it was given up on $when.";
                break;
            case 'tax':
                $bill = DB::table('company_taxes')->where('company_id', $agent->company_id)->where('eliminated', 1)->orderBy('id', 'desc')->first();
                $name = DB::table('companies')->where('company_id', $agent->company_id)->value('name');
                $cause = "Its company, $name, couldn't pay its tax bill on $when"
                    . ($bill ? ' (it had ' . number_format($bill->amount) . ' credits left)' : '')
                    . ', so the company was eliminated and all its drones became wrecks.';
                break;
            default:
                $cause = "It became a wreck on $when.";
        }
        if ($agent->plundered_at) {
            $by = Agent::where('agent_id', $agent->plundered_by)->first();
            $cause .= ' On ' . self::when((int) $agent->plundered_at) . ' it was plundered by ' . ($by ? $by->name : 'another drone') . '.';
        }

        // Its last actions (power bills left out), with the fuel left after each
        $timeline = [];
        $actions = DB::table('actions as a')->where('a.drone', $id)->where('a.protocol', '<>', 2)
            ->orderBy('a.id', 'desc')->limit(15)->get();
        foreach ($actions as $action) {
            $start = DB::table('events')->where('action', $action->id)->min('game_t');
            $fuel = DB::table('events')->where('action', $action->id)->where('drone', $id)->where('resource', self::FUEL)
                ->where('outcome', 1)->orderBy('id', 'desc')->value('new_quantity');
            $failed = $action->aborted || ($action->outcome !== null && (int) $action->outcome < 0);
            $timeline[] = [
                'game_t' => (int) $start,
                'when' => self::when((int) $start),
                'what' => self::protocolName((int) $action->protocol),
                'result' => $failed ? 'failed: ' . (self::OUTCOMES[(int) $action->outcome] ?? 'it was stopped') : 'done',
                'fuel' => $fuel,
            ];
        }
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
