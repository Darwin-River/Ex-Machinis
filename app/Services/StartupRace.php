<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The startup race (#776): the startups founded lately, their credits by
 * game days since they started, against the line they mature at and each
 * starter's usual path (a straight line from its credits after buying its
 * kit to the line, at the middle of its card's range: #833, it started at
 * the 3,000 a startup is lent, and every startup, a game hour in with
 * its 2,000 kit bought, looked far behind). Within minutes a newcomer sees
 * whether they are ahead of a typical Miner. Kept a real minute.
 */
class StartupRace
{
    /** Startups founded within this many game days */
    public const DAYS = 60;

    /** At most this many, newest first */
    public const MAX = 24;

    public const TTL = 60;

    /** What a kit costs when the House doesn't sell one (it lists both at 2,000) */
    public const KIT_PRICE = 2000;

    /** {line, start_credits, days, starters: [{key, name, days, kit, after_kit, path, fast, slow}], startups: [{company_id, name, owner_id, starter, state, points: [{age, credits}]}]} */
    public static function build(): array
    {
        return Cache::remember('startup-race', self::TTL, fn () => self::compute());
    }

    private static function compute(): array
    {
        $now = World::gameNow();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $line = Startups::grant() + Startups::matureMargin();
        $start = (int) config('forge.starting_credits');
        $starters = [];
        foreach (Release::STARTER_CARDS as $name => $card) {
            if (!isset($card['days'])) {
                continue;
            }
            $mid = intdiv($card['days'][0] + $card['days'][1], 2);
            // Its first act is to buy its kit: the path starts with what is left.
            // The band runs from the fast end of its card to the slow end (#865:
            // one straight line at the middle told most startups they were failing)
            $after = $start - self::kitPrice($card['kit']);
            $starters[] = ['key' => $name, 'name' => ucfirst($name), 'days' => $card['days'], 'kit' => $card['kit'], 'after_kit' => $after,
                'path' => [['age' => 0, 'credits' => $after], ['age' => $mid, 'credits' => $line]],
                'fast' => [['age' => 0, 'credits' => $after], ['age' => $card['days'][0], 'credits' => $line], ['age' => $card['days'][1], 'credits' => $line]],
                'slow' => [['age' => 0, 'credits' => $after], ['age' => $card['days'][1], 'credits' => $line]]];
        }
        $starterOf = self::starters();
        $startups = [];
        $rows = DB::table('companies')->whereNotNull('owner_id')->whereNotNull('founded_t')
            ->where('founded_t', '>=', $now - self::DAYS * 86400)
            ->where(fn ($q) => $q->where('startup', 1)->orWhereNotNull('matured_t'))
            ->orderByDesc('founded_t')->limit(self::MAX)
            ->get(['company_id', 'name', 'owner_id', 'founded_t', 'credits', 'startup', 'matured_t', 'eliminated_at', 'end_cause']);
        $kits = DB::table('trades as t')->join('agents as a', 'a.agent_id', '=', 't.buyer')
            ->whereIn('a.company_id', $rows->pluck('company_id')->all())->whereIn('t.resource', array_values(Release::KITS))
            ->groupBy('a.company_id')->select('a.company_id', DB::raw('MIN(t.game_t) AS t'))->pluck('t', 'company_id')->all();
        foreach ($rows as $c) {
            // Only from when it bought its kit (#863): before that it holds the
            // 3,000 it was lent, and one that never bought a kit isn't racing
            if (!isset($kits[$c->company_id])) {
                continue;
            }
            $kitDay = intdiv((int) $kits[$c->company_id] - $epoch, 86400);
            $born = intdiv((int) $c->founded_t - $epoch, 86400);
            // Its days as a startup: up to the day it matured or ended
            $until = $c->matured_t !== null ? intdiv((int) $c->matured_t - $epoch, 86400)
                : ($c->eliminated_at !== null ? intdiv((int) $c->eliminated_at - $epoch, 86400) : null);
            $q = DB::table('company_days')->where('company_id', $c->company_id)->where('day', '>=', max($born, $kitDay));
            if ($until !== null) {
                // Not the day it matured: by then it had repaid its debt (#833: a
                // matured startup's last point read 4,638, under the line)
                $q->where('day', $c->matured_t !== null ? '<' : '<=', $until);
            }
            $points = $q->orderBy('day')->get(['day', 'credits'])
                ->map(fn ($d) => ['age' => (int) $d->day - $born, 'credits' => (int) $d->credits])->all();
            if ($c->matured_t !== null) {
                // It matured on the line
                $points[] = ['age' => round(((int) $c->matured_t - (int) $c->founded_t) / 86400, 1), 'credits' => $line];
            } elseif ((int) $c->startup === 1 && $c->eliminated_at === null) {
                // Its credits now, at its age now
                $age = intdiv($now - (int) $c->founded_t, 86400);
                $points = array_values(array_filter($points, fn ($p) => $p['age'] < $age));
                $points[] = ['age' => $age, 'credits' => (int) $c->credits];
            }
            $state = $c->matured_t !== null ? 'matured' : ($c->eliminated_at !== null ? ($c->end_cause === 'closed' ? 'closed' : 'ended') : 'racing');
            $startups[] = ['company_id' => (int) $c->company_id, 'name' => $c->name, 'owner_id' => (int) $c->owner_id,
                'starter' => $starterOf[$c->company_id] ?? 'custom', 'state' => $state, 'matured_in' => $c->matured_t !== null ? round(((int) $c->matured_t - (int) $c->founded_t) / 86400, 1) : null,
                'points' => $points];
        }
        // Closest to the line first (#864: the table's order was neither)
        usort($startups, fn ($a, $b) => [$b['state'] === 'matured', end($b['points'])['credits'] ?? 0]
            <=> [$a['state'] === 'matured', end($a['points'])['credits'] ?? 0]);
        return ['line' => $line, 'start_credits' => $start, 'days' => self::DAYS, 'starters' => $starters, 'startups' => $startups];
    }

    /**
     * Which starter each recent company runs unchanged (#864): its latest
     * code against the starter scripts the lineage keeps; company_id =>
     * 'miner', 'refiner', 'maker' or 'trader'. A company whose code is
     * changed, or not worked out yet, isn't listed (it is 'custom')
     */
    private static function starters(): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('code_revisions')) {
            return [];
        }
        $starters = DB::table('code_revisions')->where('example', 'like', 'starters/%')->pluck('example', 'source_hash')->all();
        $out = [];
        $latest = DB::table('code_revisions as r')->whereNotNull('r.company_id')
            ->whereRaw('r.push_number = (SELECT MAX(q.push_number) FROM code_revisions AS q WHERE q.company_id = r.company_id)')
            ->join('companies as c', 'c.company_id', '=', 'r.company_id')->where('c.founded_t', '>=', World::gameNow() - self::DAYS * 86400)
            ->get(['r.company_id', 'r.source_hash']);
        foreach ($latest as $r) {
            if (isset($starters[$r->source_hash])) {
                $out[(int) $r->company_id] = substr($starters[$r->source_hash], strlen('starters/'));
            }
        }
        return $out;
    }

    /** What the House sells a kit for, at its cheapest (KIT_PRICE when it sells none) */
    public static function kitPrice(string $kit): int
    {
        $id = DB::table('resources')->where('name', $kit)->value('id');
        $price = $id !== null ? DB::table('house_market')->where('resource', $id)->where('side', 2)->where('price', '>', 0)->min('price') : null;
        return $price !== null ? (int) $price : self::KIT_PRICE;
    }
}
