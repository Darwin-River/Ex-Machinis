<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leaderboard (#324 step 18; companies since #367 step 2): every company
 * still trading, by net wealth. The drones a company launches belong to it,
 * so their goods count too.
 *
 * Net wealth = the company's credits
 * + the goods aboard its working drones, at their market price
 * - its starter package (credits and fuel; since #506 no kit), so a fresh
 *   release is worth 0 and wealth is what it made
 * + the shares it holds in other companies, - its own shares others hold,
 *   both at their value price (#471, #513, #568).
 * The market price of a good is what it sold for, a unit, over the last
 * three game days (#380: it was the House stations' price); a packed
 * starter kit is worth its contents. Rising is the gain over the last real week, from the hourly
 * snapshots.
 *
 * Age (#548): game days since the company was founded (founded_t). The At
 * age board (#614) ranks every company by its net wealth when it was a given
 * age, from its daily history (company_days), so a young company is compared
 * with older ones as they were at its age, and an older one's early days still
 * count; a company's page ranks its wealth now among every company's wealth at
 * the same age.
 */
class Leaderboard
{
    public const STARTER_KIT = 5901;
    public const UNPACK_PROTOCOL = 25901;
    public const FUEL = 2061;
    public const MARKET_DAYS = 3;

    /**
     * resource => market price (#380): what it sold for, a unit, over the
     * last MARKET_DAYS game days, on the public price board. A good that
     * hasn't traded lately falls back to the exchanges' price list, which
     * players don't see.
     */
    public static function prices(): array
    {
        $prices = DB::table('station_goods')->where('side', 2)->groupBy('resource')
            ->select('resource', DB::raw('MIN(price) AS price'))->pluck('price', 'resource')->all();
        foreach (DB::table('station_goods')->where('side', 1)->groupBy('resource')
                     ->select('resource', DB::raw('MIN(price) AS price'))->pluck('price', 'resource')->all() as $r => $p) {
            $prices[$r] = $p;
        }
        $since = World::gameNow() - self::MARKET_DAYS * 86400;
        foreach (DB::table('trades')->where('game_t', '>', $since)->where('units', '>', 0)->groupBy('resource')
                     ->select('resource', DB::raw('ROUND(SUM(credits) / SUM(units)) AS price'))
                     ->pluck('price', 'resource')->all() as $r => $p) {
            $prices[$r] = $p;
        }
        // A packed kit counts as its contents (#380: two kits, each unpacked by
        // the protocol 20000 above its ID)
        foreach (Release::KITS as $kitId) {
            $kit = 0;
            foreach (DB::table('resource_effects')->where('protocol', $kitId + 20000)->where('event_type', 1)
                         ->get(['resource', 'quantity']) as $part) {
                $kit += ($prices[$part->resource] ?? 0) * max(1, (int) $part->quantity);
            }
            $prices[$kitId] = $kit;
        }
        return array_map('intval', $prices);
    }

    /** What every company starts with (#324 step 6): credits and fuel (#506: no kit) */
    public static function kitValue(array $prices): int
    {
        return (int) config('forge.starting_credits') + (int) config('forge.starting_fuel') * ($prices[self::FUEL] ?? 0);
    }

    /**
     * Every company still trading: company_id => [company, drones, wealth]
     * (and drone, name: the drone it was released with). Its launched
     * drones' goods count too.
     */
    public static function wealth(): array
    {
        $prices = self::prices();
        $kit = self::kitValue($prices);
        $rows = [];
        $now = World::gameNow();
        // The House (#380: its station drones' company) isn't a player
        foreach (DB::table('companies')->whereNull('eliminated_at')->where('house', 0)
                     ->get(['company_id', 'name', 'credits', 'founded_t']) as $c) {
            $rows[$c->company_id] = ['company_id' => (int) $c->company_id, 'company' => $c->name, 'drone' => null,
                'name' => null, 'drones' => 0, 'wealth' => (int) $c->credits - $kit,
                'age' => self::age($c->founded_t, $now)];
        }
        // The released drone: the company's first, a launched clone's ancestor
        foreach (DB::table('agents')->whereIn('company_id', array_keys($rows))->groupBy('company_id')
                     ->select('company_id', DB::raw('MIN(agent_id) AS drone'))->pluck('drone', 'company_id')->all() as $co => $id) {
            $rows[$co]['drone'] = (int) $id;
        }
        foreach (DB::table('agents')->whereIn('agent_id', array_filter(array_column($rows, 'drone')))
                     ->pluck('name', 'agent_id')->all() as $id => $name) {
            foreach ($rows as $co => $row) {
                if ($row['drone'] === (int) $id) {
                    $rows[$co]['name'] = $name;
                }
            }
        }
        foreach (DB::table('agents')->whereNull('wrecked_at')->whereIn('company_id', array_keys($rows))->groupBy('company_id')
                     ->select('company_id', DB::raw('COUNT(*) AS n'))->pluck('n', 'company_id')->all() as $co => $n) {
            $rows[$co]['drones'] = (int) $n;
        }
        foreach (DB::table('sqInventory as i')->join('agents as a', 'a.agent_id', '=', 'i.drone')
                     ->whereNull('a.wrecked_at')->where('i.units', '>', 0)->whereIn('a.company_id', array_keys($rows))
                     ->select('a.company_id', 'i.resource', DB::raw('SUM(i.units) AS units'))
                     ->groupBy('a.company_id', 'i.resource')->get() as $g) {
            $rows[$g->company_id]['wealth'] += (int) $g->units * ($prices[$g->resource] ?? 0);
        }
        // Shares held in other companies, at their last trade price (#471)
        foreach (Shares::holdingsValue() as $co => $value) {
            if (isset($rows[$co])) {
                $rows[$co]['wealth'] += $value;
            }
        }
        // Its own shares that others hold count against it, at the same value
        // price (#568): selling shares at their value leaves net wealth as it was
        foreach (Shares::claimsValue() as $co => $value) {
            if (isset($rows[$co])) {
                $rows[$co]['wealth'] -= $value;
            }
        }
        // Processor tax it owes (#544) counts against it
        foreach (ProcessorTax::debts() as $co => $debt) {
            if (isset($rows[$co])) {
                $rows[$co]['wealth'] -= $debt;
            }
        }
        // Credits committed to offerings of new shares, held at the exchange
        // until the shares are issued or the offering is refunded (#485)
        foreach (DB::table('share_commitments')->groupBy('investor')
                     ->select('investor', DB::raw('SUM(credits) AS credits'))->pluck('credits', 'investor')->all() as $co => $held) {
            if (isset($rows[$co])) {
                $rows[$co]['wealth'] += (int) $held;
            }
        }
        return array_filter($rows, fn ($row) => $row['drone'] !== null);
    }

    /** Game days since founded_t, to a tenth (#548); 0 when it has none */
    public static function age($foundedT, int $now): float
    {
        return $foundedT === null ? 0.0 : round(max(0, $now - (int) $foundedT) / 86400, 1);
    }

    /** Only the companies up to maxAge game days old (#548); all of them when null */
    private static function upTo(array $rows, ?float $maxAge): array
    {
        return $maxAge === null ? $rows : array_filter($rows, fn ($row) => $row['age'] <= $maxAge);
    }

    /** The all-time board, richest first; maxAge: only companies up to that many game days old (#548) */
    public static function allTime(int $limit = 50, ?float $maxAge = null): array
    {
        $rows = array_values(self::upTo(self::wealth(), $maxAge));
        usort($rows, fn ($a, $b) => [$b['wealth'], $a['drone']] <=> [$a['wealth'], $b['drone']]);
        return array_slice($rows, 0, $limit);
    }

    /**
     * Rising: the gain over the last real week (since the latest snapshot at
     * least a week old; a company that started within the week, since 0)
     */
    /**
     * Has any company still trading a snapshot a real week old (#692)? If
     * not (a world under a week old), Rising is the same as All time
     */
    /** Game days Rising looks back (#717: a real week, while a game day takes minutes) */
    public const RISING_DAYS = 7;

    /** The game day RISING_DAYS before today */
    private static function risingFrom(): int
    {
        $epoch = (int) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        return intdiv(max(0, World::gameNow() - $epoch), 86400) - self::RISING_DAYS;
    }

    /** Whether the world has a daily history RISING_DAYS game days old to measure Rising from */
    public static function hasAWeek(): bool
    {
        return Schema::hasTable('company_days') && DB::table('company_days')->where('day', '<=', self::risingFrom())->exists();
    }

    /**
     * Rising (#717): each company's gain over the last RISING_DAYS game days,
     * its net wealth now less its daily history's net wealth on the latest
     * day at least that old (0 for a company founded since: net wealth
     * starts at 0). It was a real week of hourly snapshots, while a game day
     * takes minutes of real time
     */
    public static function rising(int $limit = 50, ?float $maxAge = null): array
    {
        $before = [];
        if (Schema::hasTable('company_days')) {
            $latest = DB::table('company_days')->where('day', '<=', self::risingFrom())
                ->groupBy('company_id')->select('company_id', DB::raw('MAX(day) AS d'));
            $before = DB::table('company_days as s')->joinSub($latest, 'l', function ($join) {
                $join->on('l.company_id', '=', 's.company_id')->on('l.d', '=', 's.day');
            })->pluck('s.net_wealth', 's.company_id')->all();
        }
        // A company that matured since then repaid its debt, which is no loss
        // of its own: it is added back (#759: newly matured companies sat at
        // the bottom of Rising at -3,400 to -3,900)
        $repaid = [];
        if (Schema::hasColumn('companies', 'matured_t')) {
            $epoch = (int) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
            $since = $epoch + self::risingFrom() * 86400;
            $fees = Schema::hasTable('code_pushes') ? DB::table('code_pushes')->groupBy('company_id')
                ->selectRaw('company_id, SUM(fee) AS fees')->pluck('fees', 'company_id')->all() : [];
            foreach (DB::table('companies')->where('matured_t', '>', $since)->pluck('company_id') as $co) {
                $repaid[(int) $co] = Startups::grant() + (int) ($fees[$co] ?? 0);
            }
        }
        $rows = [];
        foreach (self::upTo(self::wealth(), $maxAge) as $co => $row) {
            $row['gain'] = $row['wealth'] - (int) ($before[$co] ?? 0) + ($repaid[$co] ?? 0);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => [$b['gain'], $a['drone']] <=> [$a['gain'], $b['drone']]);
        return array_slice($rows, 0, $limit);
    }

    /**
     * Every company's net wealth when it was $age game days old (#614):
     * rows (richest first) of company_id, company, wealth (at that age),
     * now (its net wealth now; null once ended), ended, age (now), gap (game
     * days between the two history points it was read between: 1 or less is
     * day by day), and how many companies are too_young to have reached it or
     * ended_before it. It is read from the daily history, from 0 on the day
     * the company was founded (a fresh release is worth 0) to its wealth now,
     * linearly between points.
     */
    public static function atAge(float $age, ?int $limit = null, ?array $board = null): array
    {
        $board ??= self::wealth();
        $now = World::gameNow();
        [$companies, $points] = self::lines($board, [$age]);
        $rows = [];
        $tooYoung = $endedBefore = 0;
        foreach ($companies as $co => $c) {
            $p = $points[$co];
            $ended = $c->eliminated_at !== null || !isset($board[$co]);
            $at = self::readAt($p, $p[0][0] + $age);
            if ($at === null) {
                $ended ? $endedBefore++ : $tooYoung++;
                continue;
            }
            $rows[] = ['company_id' => (int) $co, 'company' => $c->name, 'wealth' => $at[0], 'gap' => $at[1],
                'now' => $ended ? null : $board[$co]['wealth'], 'ended' => $ended,
                'age' => self::age($c->founded_t, $ended && $c->eliminated_at !== null ? (int) $c->eliminated_at : $now),
                'drones' => $ended ? 0 : $board[$co]['drones']];
        }
        usort($rows, fn ($a, $b) => [$b['wealth'], $a['company_id']] <=> [$a['wealth'], $b['company_id']]);
        return ['age' => $age, 'rows' => $limit === null ? $rows : array_slice($rows, 0, $limit),
            'of' => count($rows), 'too_young' => $tooYoung, 'ended_before' => $endedBefore];
    }

    /**
     * Every company (but the House) and its net wealth line (#614):
     * [companies by id, company_id => [[game day, wealth], …]], from 0 on
     * the day it was founded, through its daily history, to its wealth now
     * while it trades. Only the points readAt needs at $ages are read (#857:
     * the whole history outgrew the memory a page may use): for each age,
     * the last day before it and the first on or after it, and the last day
     * of all, so readAt gives just what it would from the whole line.
     */
    private static function lines(array $board, array $ages): array
    {
        $epoch = (float) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $dayOf = fn ($t) => ($t - $epoch) / 86400;
        $companies = DB::table('companies')->where('house', 0)->whereNotNull('founded_t')
            ->get(['company_id', 'name', 'founded_t', 'eliminated_at'])->keyBy('company_id');
        $points = [];
        foreach ($companies as $co => $c) {
            $points[$co] = [[$dayOf((int) $c->founded_t), 0]];
        }
        if (Schema::hasTable('company_days') && $companies->isNotEmpty()) {
            $born = '((c.founded_t - ?) / 86400)';
            $days = [];   // company_id => [day => true]
            $add = function ($rows, array $cols) use (&$days) {
                foreach ($rows as $r) {
                    foreach ($cols as $col) {
                        if ($r->$col !== null) {
                            $days[(int) $r->company_id][(int) $r->$col] = true;
                        }
                    }
                }
            };
            $add(DB::select("SELECT c.company_id, (SELECT MAX(d.day) FROM company_days AS d WHERE d.company_id = c.company_id AND d.day > $born) AS last_day
                FROM companies AS c WHERE c.house = 0 AND c.founded_t IS NOT NULL", [$epoch]), ['last_day']);
            foreach (array_unique(array_map('floatval', $ages)) as $age) {
                $add(DB::select("SELECT c.company_id,
                    (SELECT MAX(d.day) FROM company_days AS d WHERE d.company_id = c.company_id AND d.day > $born AND d.day < $born + ?) AS before_day,
                    (SELECT MIN(d.day) FROM company_days AS d WHERE d.company_id = c.company_id AND d.day > $born AND d.day >= $born + ?) AS after_day
                    FROM companies AS c WHERE c.house = 0 AND c.founded_t IS NOT NULL",
                    [$epoch, $epoch, $age, $epoch, $epoch, $age]), ['before_day', 'after_day']);
            }
            $pairs = [];
            foreach ($days as $co => $ds) {
                foreach (array_keys($ds) as $d) {
                    $pairs[] = [$co, $d];
                }
            }
            $wealth = [];
            foreach (array_chunk($pairs, 500) as $chunk) {
                $rows = DB::table('company_days')
                    ->whereRaw('(company_id, day) IN (' . implode(',', array_fill(0, count($chunk), '(?, ?)')) . ')', array_merge(...$chunk))
                    ->get(['company_id', 'day', 'net_wealth']);
                foreach ($rows as $d) {
                    $wealth[(int) $d->company_id][(int) $d->day] = (int) $d->net_wealth;
                }
            }
            foreach ($wealth as $co => $byDay) {
                if (!isset($points[$co])) {
                    continue;
                }
                ksort($byDay);
                foreach ($byDay as $d => $w) {
                    if ($d > $points[$co][0][0]) {
                        $points[$co][] = [(float) $d, $w];
                    }
                }
            }
        }
        $today = $dayOf(World::gameNow());
        foreach ($companies as $co => $c) {
            if ($c->eliminated_at === null && isset($board[$co])) {
                $points[$co][] = [$today, $board[$co]['wealth']];   // its wealth now ends the line
            }
        }
        return [$companies, $points];
    }

    /**
     * Each company's net wealth at each of $ages (#618, the lineage map's
     * heat map), read once: company_id => [wealth => [one per age, null
     * where it never reached it], gap => […], rank => [[rank, of] or null]]
     */
    public static function atAges(array $ages, ?array $board = null): array
    {
        [$companies, $points] = self::lines($board ?? self::wealth(), $ages);
        $out = [];
        foreach ($companies as $co => $c) {
            foreach ($ages as $i => $age) {
                $at = self::readAt($points[$co], $points[$co][0][0] + $age);
                $out[$co]['wealth'][$i] = $at[0] ?? null;
                $out[$co]['gap'][$i] = $at[1] ?? null;
                $out[$co]['rank'][$i] = null;
            }
        }
        foreach ($ages as $i => $age) {
            $have = array_filter(array_keys($out), fn ($co) => $out[$co]['wealth'][$i] !== null);
            usort($have, fn ($a, $b) => [$out[$b]['wealth'][$i], $a] <=> [$out[$a]['wealth'][$i], $b]);
            foreach ($have as $r => $co) {
                $out[$co]['rank'][$i] = [$r + 1, count($have)];
            }
        }
        return $out;
    }

    /** [value, gap] at day $t on a line of [day, value] points, or null past its last point */
    private static function readAt(array $points, float $t): ?array
    {
        $last = end($points);
        if ($t > $last[0] + 0.05) {        // ages are to a tenth of a day
            return null;
        }
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            [$d1, $v1] = $points[$i - 1];
            [$d2, $v2] = $points[$i];
            if ($t <= $d2) {
                $f = $d2 > $d1 ? ($t - $d1) / ($d2 - $d1) : 1;
                return [(int) round($v1 + max(0, min(1, $f)) * ($v2 - $v1)), round($d2 - $d1, 1)];
            }
        }
        return [(int) $last[1], 0.0];
    }

    /**
     * A company's place among every company at its age (#548, #614): its net
     * wealth now ranked against each other company's net wealth when that one
     * was as old ([rank, of, age]), or null when it isn't on the board (ended,
     * or the House). A company that simply had longer to grow is compared as
     * it was at this one's age.
     */
    public static function peerRank(int $companyId, ?array $rows = null): ?array
    {
        $rows ??= self::wealth();
        if (!isset($rows[$companyId])) {
            return null;
        }
        $me = $rows[$companyId];
        $board = self::atAge($me['age'], null, $rows);
        $rank = 1 + (int) array_search($companyId, array_column($board['rows'], 'company_id'), true);
        return ['rank' => $rank, 'of' => $board['of'], 'age' => $me['age']];
    }

    /** Takes the hourly snapshot when it is due; returns whether it did */
    public static function snapshotIfDue(): bool
    {
        if (!Schema::hasColumn('wealth_snapshots', 'company_id')) {
            return false;
        }
        $last = DB::table('wealth_snapshots')->max('taken_at');
        if ($last !== null && strtotime($last) > time() - 3600) {
            return false;
        }
        $now = now();
        $rows = array_map(fn ($r) => ['company_id' => $r['company_id'], 'taken_at' => $now, 'wealth' => $r['wealth']],
            array_values(self::wealth()));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wealth_snapshots')->insert($chunk);
        }
        return true;
    }

    /**
     * Net wealth over time (#380 round 36): once a game day, every company's
     * credits and net wealth (company_days). Every day is kept (#614: the At
     * age board reads each company's history day by day; until then days
     * older than 60 were thinned to every 10th). Returns whether it took one.
     */
    public static function dailyIfDue(): bool
    {
        if (!Schema::hasTable('company_days')) {
            return false;
        }
        $clock = DB::table('game_clock')->where('id', 1)->first();
        if (!$clock || $clock->epoch === null) {
            return false;
        }
        $today = (int) floor((World::gameNow() - (float) $clock->epoch) / 86400);
        if ((int) (DB::table('company_days')->max('day') ?? -1) >= $today) {
            return false;
        }
        $credits = DB::table('companies')->pluck('credits', 'company_id')->all();
        // A matured company's cash-out value that day (#699, migration 0066)
        $cashOut = [];
        if (Schema::hasColumn('company_days', 'cash_out')) {
            $cashOut = array_map(fn ($c) => $c['total'], PlayerShares::cashOuts(DB::table('companies')->where('startup', 0)
                ->whereNotNull('owner_id')->whereNotNull('matured_t')->whereNull('eliminated_at')->pluck('company_id')->all()));
        }
        // And its share count that day, so a share's history divides by the shares it had then (#780)
        $shares = Schema::hasColumn('company_days', 'shares')
            ? DB::table('companies')->whereNotNull('player_share_count')->pluck('player_share_count', 'company_id')->all() : null;
        $rows = array_map(fn ($r) => ['company_id' => $r['company_id'], 'day' => $today,
            'credits' => (int) ($credits[$r['company_id']] ?? 0), 'net_wealth' => $r['wealth'], 'drones' => $r['drones']]
            + ($cashOut ? ['cash_out' => $cashOut[$r['company_id']] ?? null] : [])
            + ($shares !== null ? ['shares' => isset($shares[$r['company_id']]) ? (int) $shares[$r['company_id']] : null] : []),
            array_values(self::wealth()));
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('company_days')->insertOrIgnore($chunk);
        }
        return true;
    }

    /** A company's net wealth by game day, oldest first */
    public static function history(int $companyId): array
    {
        $hasCash = Schema::hasColumn('company_days', 'cash_out');
        $rows = DB::table('company_days')->where('company_id', $companyId)->orderBy('day')
            ->get(array_merge(['day', 'credits', 'net_wealth', 'drones'], $hasCash ? ['cash_out'] : [],
                Schema::hasColumn('company_days', 'shares') ? ['shares'] : []));
        // The bank's selling price that day (#699): a share's cash-out value
        // plus its dividends over the premiumDays() game days before (#781), as
        // PlayerShares::prices works it out now
        $paid = DB::table('player_dividends')->where('company_id', $companyId)->groupBy('day')
            ->selectRaw('day, SUM(amount) AS amount')->pluck('amount', 'day')->all();
        $premiumDays = PlayerShares::premiumDays();
        return $rows->map(function ($d) use ($paid, $hasCash, $premiumDays) {
            $cash = $hasCash && $d->cash_out !== null ? (int) $d->cash_out : null;
            // Over the shares it had that day (#780)
            $count = max(1, (int) ($d->shares ?? PlayerShares::COUNT));
            $share = $cash === null ? null : intdiv(max(0, $cash), $count);
            $div = 0;
            foreach ($paid as $day => $amount) {
                if ($day >= (int) $d->day - $premiumDays && $day < (int) $d->day) {
                    $div += (int) $amount;
                }
            }
            return ['day' => (int) $d->day, 'credits' => (int) $d->credits,
                'net_wealth' => (int) $d->net_wealth, 'drones' => (int) $d->drones,
                'cash_out' => $cash, 'cash_out_share' => $share,
                'bank_sells_at' => $share === null ? null : $share + intdiv($div, $count), 'shares' => $count];
        })->all();
    }
}
