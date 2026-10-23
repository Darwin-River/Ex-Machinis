<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Protocol extends Model
{
    public $timestamps = false;

    /** Event types in the action tables */
    private const INCREMENT = 1;
    private const DECREMENT = 2;

    /** Default game time scale when the game clock hasn't been created yet */
    public const DEFAULT_TIME_SCALE = 60;

    /**
     * Everything a player needs to know about performing this protocol,
     * worked out from the action tables (resource_effects, market_effects,
     * location_effects):
     *
     * - modules:  resources the drone must have; they are locked while the
     *             action runs and given back at the end
     * - consumed: resources used up
     * - produced: resources made (mined ones depend on the location)
     * - steps:    every effect in time order (game seconds)
     * - duration: the action's length in game seconds (null for travel,
     *             which depends on the distance)
     */
    public function details(): array
    {
        $effects = DB::table('resource_effects')
            ->leftJoin('resources', 'resources.id', '=', 'resource_effects.resource')
            ->where('protocol', $this->id)
            ->orderBy('time')->orderBy('resource_effects.id')
            ->get(['resource_effects.*', 'resources.name as resource_name']);
        $market = DB::table('market_effects')->where('protocol', $this->id)->get();
        $location = DB::table('location_effects')->where('protocol', $this->id)->get();

        // Net change, per resource of the acting drone, in its unlocked state
        $net = [];
        $locked = [];
        $names = [];
        $mined = [];
        foreach ($effects as $e) {
            if ($e->drone != 0 || $e->resource < 0 || $e->quantity < 0) {
                continue; // a transfer, or set by the parameters
            }
            $sign = $e->event_type == self::INCREMENT ? 1 : ($e->event_type == self::DECREMENT ? -1 : 0);
            $names[$e->resource] = $e->resource_name ?? ('Resource ' . $e->resource);
            $net[$e->resource] = ($net[$e->resource] ?? 0) + ($e->locked ? 0 : $sign * $e->quantity);
            if ($e->locked) {
                $locked[$e->resource] = true;
            }
            if ($e->abundancies) {
                $mined[$e->resource] = true;
            }
        }

        $modules = $consumed = $produced = [];
        foreach ($net as $id => $change) {
            $item = ['id' => $id, 'name' => $names[$id]];
            if ($change < 0) {
                $consumed[] = $item + ['quantity' => -$change];
            } elseif ($change > 0) {
                $produced[] = $item + ['quantity' => $change, 'mined' => isset($mined[$id])];
            } else {
                $modules[] = $item + ['locked' => isset($locked[$id])];
            }
        }

        $steps = [];
        foreach ($effects as $e) {
            $steps[] = [
                'time' => (int) $e->time,
                'what' => self::describeResourceEffect($e),
            ];
        }
        foreach ($market as $m) {
            $steps[] = [
                'time' => (int) $m->time,
                'what' => $m->event_type == 4
                    ? 'Places a sell order for the resource (price and minimum to keep from the parameters)'
                    : 'Places a buy order for the resource (price and maximum to hold from the parameters)',
            ];
        }
        foreach ($location as $l) {
            $steps[] = [
                'time' => (int) $l->time,
                'what' => 'Moves the drone to ' . ($l->location == -1 ? 'the destination given as a parameter' : 'location ' . $l->location)
                    . '. The trip takes 2 × √(distance ÷ acceleration), so this step and the ones after it come later the further the drone goes.',
            ];
        }
        usort($steps, fn ($a, $b) => $a['time'] <=> $b['time']);

        $times = array_column($steps, 'time');

        return [
            'modules' => $modules,
            'consumed' => $consumed,
            'produced' => $produced,
            'steps' => $steps,
            'travel' => $location->isNotEmpty(),
            'duration' => $location->isNotEmpty() ? null : ($times ? max($times) : 0),
        ];
    }

    /** One resource effect, in words */
    private static function describeResourceEffect(object $e): string
    {
        $resource = $e->resource < 0
            ? 'the resource given as parameter ' . -$e->resource
            : ($e->resource_name ?? 'resource ' . $e->resource);
        $quantity = $e->quantity < 0 ? 'the quantity given as parameter ' . -$e->quantity : $e->quantity;
        $drone = $e->drone == 0 ? 'this drone' : ($e->drone < 0 ? 'the drone given as parameter ' . -$e->drone : 'drone ' . $e->drone);
        $state = $e->locked ? ' (locked: in use)' : ($e->installed ? ' (installed)' : '');

        if ($e->event_type == self::INCREMENT) {
            $verb = $e->abundancies ? 'Mines' : 'Adds';
            $text = "$verb $quantity × $resource$state to $drone";
            if ($e->abundancies) {
                $text .= ", times how abundant it is at the drone's location";
            }
            return $text;
        }
        if ($e->event_type == self::DECREMENT) {
            return "Removes $quantity × $resource$state from $drone";
        }
        return "Event type {$e->event_type} on $resource";
    }

    /** The game time scale (game seconds per real second) */
    public static function timeScale(): float
    {
        if (Schema::hasTable('game_clock')) {
            $scale = DB::table('game_clock')->where('id', 1)->value('scale');
            if ($scale > 0) {
                return (float) $scale;
            }
        }
        return self::DEFAULT_TIME_SCALE;
    }

    /** A duration in seconds, in words ("2 hours 5 minutes") */
    public static function humanDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds == 1 ? '' : 's');
        }
        $parts = [];
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60] as $unit => $size) {
            if ($seconds >= $size) {
                $n = intdiv($seconds, $size);
                $seconds -= $n * $size;
                $parts[] = $n . ' ' . $unit . ($n == 1 ? '' : 's');
            }
            if (count($parts) == 2) {
                break;
            }
        }
        return implode(' ', $parts);
    }
}
