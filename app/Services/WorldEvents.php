<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events that explain the charts (#720). Round 70's players read the
 * biggest moves in the time charts (the 8-drone cap's scrap, the outage of
 * days 2161 to 2170) as their own failure or as bugs: nothing on a chart
 * said why. Each time chart now draws these as thin labelled marks.
 *
 * - The world's events are one list, config/world-events.php: rule changes,
 *   outages and repairs, each with its game day, a label and a sentence.
 *   An event can take its time from the migration that brought it in, so
 *   the day is right on every server. Worked out once an hour (cached).
 * - A company's own events come from small tables and indexed lookups, never
 *   from events: the day it matured (companies.matured_t), its code pushes
 *   (code_pushes), shares sold to the bank and big share trades
 *   (player_share_trades), the equipment it invested in with its payback
 *   (#778), and the day it ended.
 */
class WorldEvents
{
    /** Real seconds the world's events are kept for */
    public const TTL = 3600;

    /** Shares in one game day's trading that make a big trade (a tenth of a company) */
    public const BIG_TRADE = 10;

    /** A company's own events at most (the newest) */
    public const MAX_COMPANY = 120;

    /** Every world event this server can date, oldest first (cached) */
    public static function all(): array
    {
        // A new engine start is shown at once (#750), not up to an hour late
        $starts = Schema::hasTable('engine_starts') ? (int) DB::table('engine_starts')->max('id') : 0;
        return Cache::remember('world-events:' . $starts, self::TTL, fn () => self::build());
    }

    /** Works the world's events out from config/world-events.php */
    public static function build(): array
    {
        $epoch = self::epoch();
        $applied = Schema::hasTable('schema_version')
            ? DB::table('schema_version')->selectRaw('name, UNIX_TIMESTAMP(applied_at) AS t')->pluck('t', 'name')->all() : [];
        $reset = null;
        $out = [];
        foreach ((array) config('world-events', []) as $e) {
            $t = null;
            if (!empty($e['migration']) && isset($applied[$e['migration']])) {
                $t = self::gameTimeAt((int) $applied[$e['migration']]);
            } elseif (!empty($e['reset'])) {
                $reset ??= (int) (DB::table('companies')->where('end_cause', 'reset')->max('eliminated_at') ?? 0);
                $t = $reset ?: null;
            }
            if ($t === null && isset($e['day'])) {
                $t = $epoch + (int) $e['day'] * 86400;
            }
            if ($t === null) {
                continue;
            }
            $day = intdiv(max(0, $t - $epoch), 86400);
            $toDay = isset($e['to_day']) ? max($day, (int) $e['to_day']) : null;
            // A text names its days by date (#753): {from} and {to}
            $text = strtr($e['text'], ['{from}' => GameDate::ofDay($day), '{to}' => GameDate::ofDay($toDay ?? $day)]);
            $out[] = ['key' => $e['key'], 'kind' => $e['kind'], 'day' => $day, 'to_day' => $toDay,
                'game_time' => $t, 'when' => self::when($t, $epoch), 'date' => GameDate::ofDay($day),
                'to_date' => $toDay !== null ? GameDate::ofDay($toDay) : null, 'label' => $e['label'], 'text' => $text,
                'issue' => $e['issue'] ?? null];
        }
        foreach (self::restarts($epoch) as $r) {
            $out[] = $r;
        }
        foreach (self::massEnds($epoch) as $r) {
            $out[] = $r;
        }
        usort($out, fn ($a, $b) => $a['game_time'] <=> $b['game_time']);
        return $out;
    }

    /** Matured companies ending on one game day that make a world event (#837) */
    public const MASS_END = 5;

    /** End causes in words, for a mass end's text */
    private const END_WORDS = ['dissolved' => 'dissolved, out of credits for too long', 'wound_up' => 'wound up after selling nothing for '
        . WindUp::DAYS . ' game days', 'closed' => 'closed by their owners'];

    /**
     * Game days on which MASS_END or more matured companies ended (#837: on
     * 2 May 2038 every matured unchanged starter dissolved in the same
     * minute, and no chart marked it). A world reset is its own event
     */
    public static function massEnds(int $epoch): array
    {
        $days = DB::table('companies')->whereNotNull('eliminated_at')->whereNotNull('matured_t')->where('startup', 0)
            ->where(fn ($q) => $q->whereNull('end_cause')->orWhere('end_cause', '<>', 'reset'))
            ->selectRaw('FLOOR((eliminated_at - ?) / 86400) AS day, MIN(eliminated_at) AS t, COUNT(*) AS n, '
                . "GROUP_CONCAT(IFNULL(end_cause, 'dissolved')) AS causes", [$epoch])
            ->groupBy('day')->havingRaw('COUNT(*) >= ?', [self::MASS_END])->orderBy('day')->get();
        $out = [];
        foreach ($days as $d) {
            $day = max(0, (int) $d->day);
            $by = array_count_values(explode(',', (string) $d->causes));
            arsort($by);
            $parts = [];
            foreach ($by as $cause => $k) {
                $parts[] = $k . ' ' . (self::END_WORDS[$cause] ?? $cause);
            }
            $out[] = ['key' => 'mass-end-' . $day, 'kind' => 'ends', 'day' => $day, 'to_day' => null,
                'game_time' => (int) $d->t, 'when' => self::when((int) $d->t, $epoch), 'date' => GameDate::ofDay($day),
                'to_date' => null, 'label' => (int) $d->n . ' companies ended',
                'text' => (int) $d->n . ' matured companies ended that game day: ' . implode('; ', $parts) . '.',
                'issue' => 837];
        }
        return $out;
    }

    /** Game days with engine restarts kept as marks at most (the newest) */
    public const MAX_RESTART_DAYS = 200;

    /**
     * The game engine's restarts (#750: drone pages said "The engine
     * restarted" and the charts didn't), one a game day: each sent every Lua
     * drone back to its launched function
     */
    public static function restarts(int $epoch): array
    {
        if (!Schema::hasTable('engine_starts')) {
            return [];
        }
        $days = DB::table('engine_starts')
            ->selectRaw('FLOOR((game_t - ?) / 86400) AS day, MIN(game_t) AS t, COUNT(*) AS n', [$epoch])
            ->groupBy('day')->orderByDesc('day')->limit(self::MAX_RESTART_DAYS)->get();
        $out = [];
        foreach ($days->reverse() as $d) {
            $day = max(0, (int) $d->day);
            $n = (int) $d->n;
            $out[] = ['key' => 'engine-restart-' . $day, 'kind' => 'restart', 'day' => $day, 'to_day' => null,
                'game_time' => (int) $d->t, 'when' => self::when((int) $d->t, $epoch), 'date' => GameDate::ofDay($day),
                'to_date' => null, 'label' => $n > 1 ? 'Engine restarted ×' . $n : 'Engine restarted',
                'text' => 'The game engine restarted' . ($n > 1 ? ' ' . $n . ' times that game day' : '')
                    . ': every Lua drone ran its launched function again, with its saved variables.',
                'issue' => 750];
        }
        return $out;
    }

    /**
     * A company's own events, oldest first: matured, pushes, shares sold to
     * the bank, big trades and its end. Each: kind, day, game_time, when,
     * label, text
     */
    public static function company(int $companyId): array
    {
        $c = DB::table('companies')->where('company_id', $companyId)
            ->first(['company_id', 'matured_t', 'eliminated_at', 'end_cause', 'startup']);
        if (!$c) {
            return [];
        }
        $epoch = self::epoch();
        $out = [];
        $add = function (string $kind, int $t, string $label, string $text) use (&$out, $epoch) {
            $out[] = ['kind' => $kind, 'day' => intdiv(max(0, $t - $epoch), 86400), 'game_time' => $t,
                'when' => self::when($t, $epoch), 'label' => $label, 'text' => $text];
        };
        if ($c->matured_t !== null) {
            $add('matured', (int) $c->matured_t, 'Matured', 'It matured: its code was locked and its shares went on sale.');
        }
        // Pushes (and a startup's reprograms): one mark a game day
        if (Schema::hasTable('code_pushes')) {
            $pushes = DB::table('code_pushes')->where('company_id', $companyId)->orderByDesc('id')->limit(self::MAX_COMPANY)
                ->get(['number', 'game_t', 'drones'])->reverse()
                ->groupBy(fn ($p) => intdiv(max(0, (int) $p->game_t - $epoch), 86400));
            foreach ($pushes as $ps) {
                $first = $ps->first();
                $n = $ps->pluck('number')->map(fn ($v) => (int) $v)->all();
                $label = count($n) > 1 ? 'Pushes ' . min($n) . '–' . max($n) : 'Push ' . $n[0];
                $add('push', (int) $first->game_t, $label, count($n) > 1
                    ? count($n) . ' code pushes (' . min($n) . ' to ' . max($n) . '): its drones started over on new code.'
                    : 'Code push ' . $n[0] . ': its ' . (int) $first->drones . ' working drones started over on new code.');
            }
        }
        // Share trades by game day: sales to the bank, and big trades
        if (Schema::hasTable('player_share_trades')) {
            // New shares the company sold (#780) aren't the bank's
            $issued = Schema::hasColumn('player_share_trades', 'issued') ? 'AND issued = 0' : '';
            $days = DB::table('player_share_trades')->where('company_id', $companyId)
                ->selectRaw("FLOOR((game_t - ?) / 86400) AS day, MIN(game_t) AS t,
                    SUM(CASE WHEN buyer_id IS NULL THEN units ELSE 0 END) AS to_bank,
                    SUM(CASE WHEN seller_id IS NULL $issued THEN units ELSE 0 END) AS from_bank,
                    SUM(CASE WHEN seller_id IS NOT NULL AND buyer_id IS NOT NULL THEN units ELSE 0 END) AS players", [$epoch])
                ->groupBy('day')->orderByDesc('day')->limit(self::MAX_COMPANY)->get();
            foreach ($days->reverse() as $d) {
                $parts = [];
                if ((int) $d->to_bank > 0) {
                    $parts[] = self::shares((int) $d->to_bank) . ' sold to the bank';
                }
                if ((int) $d->from_bank >= self::BIG_TRADE) {
                    $parts[] = self::shares((int) $d->from_bank) . ' bought from the bank';
                }
                if ((int) $d->players >= self::BIG_TRADE) {
                    $parts[] = self::shares((int) $d->players) . ' traded between players';
                }
                if ($parts) {
                    $add('shares', (int) $d->t, (int) $d->to_bank > 0 ? 'Sold to bank' : 'Big trade',
                        ucfirst(implode('; ', $parts)) . ' that game day.');
                }
            }
        }
        // Its offerings of new shares (#780): the day each opened, what sold and what it raised
        foreach (array_reverse(PlayerShares::offerings($companyId)) as $o) {
            $add('offered', $o['created_t'], 'Offered ' . $o['units'] . ' shares',
                'It offered ' . $o['units'] . ' new shares at its cash-out value a share, to fund its growth: '
                . ($o['sold'] > 0 ? $o['sold'] . ' sold, raising ' . number_format($o['raised']) . ' credits' : 'none sold yet')
                . ($o['closed_t'] !== null && $o['sold'] < $o['units'] ? ' (the rest withdrawn)' : '') . '.');
        }
        // What it invested (#778): equipment it bought (kits, modules and
        // hulls) by game day, with how long its profit a drone takes to pay
        // that back. #759 stopped counting equipment as a loss; this shows
        // it as progress
        $drones = DB::table('agents')->where('company_id', $companyId)->pluck('agent_id')->all();
        if ($drones) {
            $bought = DB::table('trades as t')->leftJoin('resources as r', 'r.id', '=', 't.resource')
                ->whereIn('t.buyer', $drones)->whereNotIn('t.seller', $drones)->where('t.resource', '>=', CompanyEarnings::EQUIPMENT_FROM)
                ->whereNotNull('t.game_t')
                ->selectRaw('FLOOR((t.game_t - ?) / 86400) AS day, MIN(t.game_t) AS t, r.name, SUM(t.units) AS units, SUM(t.credits) AS credits', [$epoch])
                ->groupBy('day', 'r.name')->orderByDesc('day')->limit(self::MAX_COMPANY * 3)->get()->groupBy('day');
            $perDrone = $bought->isNotEmpty() ? self::profitPerDrone($companyId) : null;
            foreach ($bought->sortKeys() as $items) {
                $spent = (int) $items->sum('credits');
                $what = $items->map(fn ($i) => ($i->name ?? 'equipment') . ((int) $i->units > 1 ? ' ×' . (int) $i->units : ''))->implode(', ');
                $payback = $perDrone !== null && $perDrone > 0 ? (int) ceil($spent / $perDrone) : null;
                $add('invest', (int) $items->min('t'), 'Invested ' . number_format($spent),
                    'It invested ' . number_format($spent) . ' credits: ' . $what . '.'
                    . ($payback !== null ? ' At its profit a drone now (' . number_format($perDrone) . ' a game day), that pays back in about '
                        . $payback . ' game ' . ($payback === 1 ? 'day' : 'days') . '.' : ' Its drones aren\'t making a profit now, so it hasn\'t started paying back.'));
            }
        }
        if ($c->eliminated_at !== null) {
            $add('ended', (int) $c->eliminated_at, 'Ended', 'It ended' . ($c->end_cause ? ' (' . $c->end_cause . ')' : '') . '.');
        }
        usort($out, fn ($a, $b) => $a['game_time'] <=> $b['game_time']);
        return array_slice($out, -self::MAX_COMPANY);
    }

    /** A company's profit a working drone a game day, over its last 7 game days (#778), or null with no working drone */
    public static function profitPerDrone(int $companyId): ?int
    {
        $working = DB::table('agents')->where('company_id', $companyId)->whereNull('wrecked_at')->count();
        if ($working === 0) {
            return null;
        }
        $days = CompanyEarnings::daily($companyId, 7);
        return (int) round(array_sum(array_column($days, 'net')) / max(1, count($days)) / $working);
    }

    /**
     * What the charts draw (ExCharts' marks option): the world's events and,
     * with a company, its own. Each: day, to_day (a stretch, or null), kind,
     * scope ('world' or 'company'), label, text
     */
    public static function marks(?int $companyId = null): array
    {
        $marks = array_map(fn ($e) => ['day' => $e['day'], 'to_day' => $e['to_day'], 'kind' => $e['kind'], 'scope' => 'world',
            'label' => $e['label'], 'text' => $e['text']], self::all());
        if ($companyId !== null) {
            foreach (self::company($companyId) as $e) {
                $marks[] = ['day' => $e['day'], 'to_day' => null, 'kind' => $e['kind'], 'scope' => 'company',
                    'label' => $e['label'], 'text' => $e['text']];
            }
        }
        usort($marks, fn ($a, $b) => $a['day'] <=> $b['day']);
        return $marks;
    }

    /** The game time at a real (Unix) time, from the clock's history of rate changes */
    public static function gameTimeAt(int $real): int
    {
        // The last rate change before it; rows go in time order, so this
        // walks back from the newest only as far as it has to
        $h = Schema::hasTable('game_clock_history')
            ? DB::table('game_clock_history')->where('real_time', '<=', $real)->orderByDesc('id')->first() : null;
        if ($h) {
            return (int) round($h->game_time + ($real - $h->real_time) * $h->scale);
        }
        $clock = DB::table('game_clock')->where('id', 1)->first();
        return $clock ? (int) round($clock->game_anchor + ($real - $clock->real_anchor) * $clock->scale) : $real;
    }

    private static function epoch(): int
    {
        return (int) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
    }

    /** Its date and time (#753), as DronePage::when */
    private static function when(int $t, int $epoch): string
    {
        return GameDate::at($t);
    }

    private static function shares(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'share' : 'shares');
    }
}
