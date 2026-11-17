<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The seedling race (#776): the seedlings started lately, their credits by
 * game days since they started, against the line they mature at and each
 * starter's usual path (a straight line from its starting credits to the
 * line, at the middle of its card's range). Within minutes a newcomer sees
 * whether they are ahead of a typical Miner. Kept a real minute.
 */
class SeedlingRace
{
    /** Seedlings started within this many game days */
    public const DAYS = 60;

    /** At most this many, newest first */
    public const MAX = 24;

    public const TTL = 60;

    /** {line, start_credits, days, starters: [{name, days, path}], seedlings: [{company_id, name, owner_id, state, points: [{age, credits}]}]} */
    public static function build(): array
    {
        return Cache::remember('seedling-race', self::TTL, fn () => self::compute());
    }

    private static function compute(): array
    {
        $now = World::gameNow();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $line = Seedlings::grant() + Seedlings::matureMargin();
        $start = (int) config('forge.starting_credits');
        $starters = [];
        foreach (Release::STARTER_CARDS as $name => $card) {
            if (!isset($card['days'])) {
                continue;
            }
            $mid = intdiv($card['days'][0] + $card['days'][1], 2);
            $starters[] = ['name' => ucfirst($name), 'days' => $card['days'],
                'path' => [['age' => 0, 'credits' => $start], ['age' => $mid, 'credits' => $line]]];
        }
        $seedlings = [];
        $rows = DB::table('companies')->whereNotNull('owner_id')->whereNotNull('founded_t')
            ->where('founded_t', '>=', $now - self::DAYS * 86400)
            ->where(fn ($q) => $q->where('seedling', 1)->orWhereNotNull('matured_t'))
            ->orderByDesc('founded_t')->limit(self::MAX)
            ->get(['company_id', 'name', 'owner_id', 'founded_t', 'credits', 'seedling', 'matured_t', 'eliminated_at', 'end_cause']);
        foreach ($rows as $c) {
            $born = intdiv((int) $c->founded_t - $epoch, 86400);
            // Its days as a seedling: up to the day it matured or ended
            $until = $c->matured_t !== null ? intdiv((int) $c->matured_t - $epoch, 86400)
                : ($c->eliminated_at !== null ? intdiv((int) $c->eliminated_at - $epoch, 86400) : null);
            $q = DB::table('company_days')->where('company_id', $c->company_id)->where('day', '>=', $born);
            if ($until !== null) {
                $q->where('day', '<=', $until);
            }
            $points = $q->orderBy('day')->get(['day', 'credits'])
                ->map(fn ($d) => ['age' => (int) $d->day - $born, 'credits' => (int) $d->credits])->all();
            if ((int) $c->seedling === 1 && $c->eliminated_at === null) {
                // Its credits now, at its age now
                $age = intdiv($now - (int) $c->founded_t, 86400);
                $points = array_values(array_filter($points, fn ($p) => $p['age'] < $age));
                $points[] = ['age' => $age, 'credits' => (int) $c->credits];
            }
            $state = $c->matured_t !== null ? 'matured' : ($c->eliminated_at !== null ? ($c->end_cause === 'closed' ? 'closed' : 'ended') : 'racing');
            $seedlings[] = ['company_id' => (int) $c->company_id, 'name' => $c->name, 'owner_id' => (int) $c->owner_id,
                'state' => $state, 'matured_in' => $c->matured_t !== null ? round(((int) $c->matured_t - (int) $c->founded_t) / 86400, 1) : null,
                'points' => $points];
        }
        return ['line' => $line, 'start_credits' => $start, 'days' => self::DAYS, 'starters' => $starters, 'seedlings' => $seedlings];
    }
}
