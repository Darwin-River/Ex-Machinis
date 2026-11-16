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
 *   (player_share_trades), and the day it ended.
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
        return Cache::remember('world-events', self::TTL, fn () => self::build());
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
            $out[] = ['key' => $e['key'], 'kind' => $e['kind'], 'day' => $day, 'to_day' => $toDay,
                'game_time' => $t, 'when' => self::when($t, $epoch), 'label' => $e['label'], 'text' => $e['text'],
                'issue' => $e['issue'] ?? null];
        }
        usort($out, fn ($a, $b) => $a['game_time'] <=> $b['game_time']);
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
            ->first(['company_id', 'matured_t', 'eliminated_at', 'end_cause', 'seedling']);
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
        // Pushes (and a seedling's reprograms): one mark a game day
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
            $days = DB::table('player_share_trades')->where('company_id', $companyId)
                ->selectRaw('FLOOR((game_t - ?) / 86400) AS day, MIN(game_t) AS t,
                    SUM(CASE WHEN buyer_id IS NULL THEN units ELSE 0 END) AS to_bank,
                    SUM(CASE WHEN seller_id IS NULL THEN units ELSE 0 END) AS from_bank,
                    SUM(CASE WHEN seller_id IS NOT NULL AND buyer_id IS NOT NULL THEN units ELSE 0 END) AS players', [$epoch])
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
        if ($c->eliminated_at !== null) {
            $add('ended', (int) $c->eliminated_at, 'Ended', 'It ended' . ($c->end_cause ? ' (' . $c->end_cause . ')' : '') . '.');
        }
        usort($out, fn ($a, $b) => $a['game_time'] <=> $b['game_time']);
        return array_slice($out, -self::MAX_COMPANY);
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

    /** "day 2134, 06:00", as DronePage::when, without a query each */
    private static function when(int $t, int $epoch): string
    {
        $since = max(0, $t - $epoch);
        return sprintf('day %d, %02d:%02d', intdiv($since, 86400), intdiv($since % 86400, 3600), intdiv($since % 3600, 60));
    }

    private static function shares(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'share' : 'shares');
    }
}
