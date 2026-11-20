<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The economy dashboard (#698): is the market busy, are the companies making
 * money, what is traded where, and who is winning. A compact strip on the
 * home page, the whole thing at /dashboard and GET /api/v1/dashboard.
 *
 * Every figure is worked out here, on the server. A page view never waits
 * for it (#723): it gets the last one worked out, and if that is older than
 * TTL real seconds, the scheduler rebuilds it (Kept::keepAll, #738),
 * never two at once (a lock). On the live database a rebuild took about 90 real
 * seconds; with a 60-second cache, each view after it ran out started one
 * of its own, which kept MySQL busy and slowed the game from round 70.
 * Game days count from the world's first (game_clock.epoch).
 */
class Dashboard
{
    /** Real seconds behind the clock after which a kept page says it is old (#738) */
    public const STALE_REAL_SECONDS = 600;

    /** Real seconds after which a view starts a rebuild of the dashboard */
    public const TTL = 120;

    /** Game days the charts of trading and profit cover */
    public const DAYS = 60;

    /** Game days the "what is traded" chart covers */
    public const GOODS_DAYS = 14;

    /** Places with their own colour in the trading chart; the rest are "Elsewhere" */
    public const PLACES = 5;

    /** Trades on the last full day below this share of the norm: the banner says the market is quiet */
    public const QUIET = 0.5;

    /** The banner and tiles, for the home page */
    public static function compact(): array
    {
        return self::kept('dashboard:compact', fn () => self::summary());
    }

    /** Everything, for /dashboard and the API */
    public static function full(): array
    {
        return self::kept('dashboard:full', fn () => self::build());
    }

    /**
     * How far behind the world's clock figures worked out at game time $at
     * are, as a note for the page, or null when they are under
     * STALE_REAL_SECONDS of real time (or a game day) old (#738)
     */
    public static function staleNote(?int $at): ?string
    {
        if ($at === null) {
            return null;
        }
        // Old: more than STALE_REAL_SECONDS of real time behind, or a game
        // day when the clock runs slow (#738: at 1,800x a rebuild every two
        // real minutes is 2.5 game days, and a game day's threshold always warned)
        $scale = (float) (DB::table('game_clock')->where('id', 1)->value('scale') ?? 1);
        $behind = World::gameNow() - $at;
        if ($behind < max(86400, self::STALE_REAL_SECONDS * $scale)) {
            return null;
        }
        $days = (int) floor($behind / 86400);
        return 'These figures are ' . $days . ' game day' . ($days === 1 ? '' : 's')
            . ' old: they are worked out again each minute, and this copy has not been yet.';
    }

    /**
     * How long ago, in real time, figures worked out at game time $at were:
     * "just now" or "N real minutes ago" (#760: the pages said "each minute"
     * while their figures were 1 to 5 real minutes old)
     */
    public static function agoText(?int $at): string
    {
        if ($at === null) {
            return '';
        }
        $scale = max(1.0, (float) (DB::table('game_clock')->where('id', 1)->value('scale') ?? 1));
        $minutes = (int) floor(max(0, World::gameNow() - $at) / $scale / 60);
        return $minutes < 1 ? 'just now' : $minutes . ' real ' . ($minutes === 1 ? 'minute' : 'minutes') . ' ago';
    }

    /** The banner and tiles worked out now (the scheduler's rebuild, Kept::keepAll) */
    public static function summaryNow(): array
    {
        return self::summary();
    }

    /** Everything worked out now (the scheduler's rebuild, Kept::keepAll) */
    public static function buildNow(): array
    {
        return self::build();
    }

    /** The last $key worked out (Kept), rebuilt by the scheduler once TTL old */
    private static function kept(string $key, callable $work): array
    {
        return Kept::get($key, self::TTL, $work);
    }

    /** Today's game day, and the game time it started at */
    private static function today(): array
    {
        $epoch = (int) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $today = intdiv(max(0, World::gameNow() - $epoch), 86400);
        return [$epoch, $today];
    }

    /** The banner and tiles, with what they are worked out from */
    private static function summary(?array $parts = null): array
    {
        $parts ??= self::parts();
        [, $today] = self::today();
        $clock = LaunchQueue::status();
        $activity = $parts['activity'];
        $byDay = collect($activity['days'])->keyBy('day');
        $full = $today - 1;
        // The norm: the average day over the 60 before the last full one,
        // counting only days the world was running (a new world has fewer)
        $first = $activity['first_day'];
        $normDays = range(max($first, $full - self::DAYS), $full - 1);
        $normDays = $full - 1 >= max($first, $full - self::DAYS) ? $normDays : [];
        $norm = fn (string $k) => count($normDays) ? array_sum(array_map(fn ($d) => $byDay[$d][$k] ?? 0, $normDays)) / count($normDays) : null;
        $trades = (int) ($byDay[$full]['trades'] ?? 0);
        $credits = (int) ($byDay[$full]['credits'] ?? 0);
        $normTrades = $norm('trades');
        $normCredits = $norm('credits');
        $ratio = $normTrades ? $trades / $normTrades : null;

        $d = $parts['drones'];
        $m = $parts['matured'];
        $p = $parts['profit'];
        $week = array_sum(array_map(fn ($r) => $r['net'], array_filter($p, fn ($r) => $r['day'] >= $today - 7 && $r['day'] < $today)));
        $lastWeek = array_sum(array_map(fn ($r) => $r['net'], array_filter($p, fn ($r) => $r['day'] >= $today - 14 && $r['day'] < $today - 7)));
        // The players /players lists (#716: 8 here, 4 there): the public's
        // accounts, a class group's pupils left out
        // (#867: only accounts with a confirmed address, as /players lists them)
        $players = (int) PlayerBoards::played(DB::table('users')->whereNotNull('login')->whereNull('class_group_id')->whereNotNull('email_verified_at'))->count();
        $newPlayers = (int) PlayerBoards::played(DB::table('users')->whereNotNull('login')->whereNull('class_group_id')->whereNotNull('email_verified_at')
            ->where('created_at', '>=', now()->subDays(7)))->count();

        $slowed = (bool) $clock['slowed'];
        return [
            'as_of' => DronePage::when(World::gameNow()),
            'as_of_t' => World::gameNow(),
            'today' => $today,
            'banner' => [
                'day' => $full,
                'trades' => $trades,
                'norm_trades' => $normTrades === null ? null : (int) round($normTrades),
                'ratio' => $ratio === null ? null : round($ratio, 2),
                'quiet' => $ratio !== null && $ratio < self::QUIET,
                'slowed' => $slowed,
                'real_seconds_per_game_day' => $clock['real_seconds_per_game_day'],
                'target_real_seconds_per_game_day' => $clock['target_real_seconds_per_game_day'],
                'engine_behind_seconds' => $clock['engine']['behind_seconds'],
                'engine_running' => $clock['engine']['running'],
                'processor_tax_multiplier' => $clock['processor_tax']['congestion'],
                'norm_days' => count($normDays),
                'text' => self::bannerText($full, $trades, $normTrades, $ratio, $clock, count($normDays)),
            ],
            'tiles' => [
                'trades' => ['value' => $trades, 'norm' => $normTrades === null ? null : (int) round($normTrades),
                    'change_percent' => self::percent($trades, $normTrades)],
                'credits' => ['value' => $credits, 'norm' => $normCredits === null ? null : (int) round($normCredits),
                    'change_percent' => self::percent($credits, $normCredits)],
                'drones' => ['working' => $d['working'], 'wrecks' => $d['wrecks'],
                    'wreck_share' => $d['working'] + $d['wrecks'] ? round($d['wrecks'] / ($d['working'] + $d['wrecks']), 3) : 0.0,
                    'week_ago' => $d['week_ago'], 'change' => $d['week_ago'] === null ? null : $d['working'] - $d['week_ago']],
                'matured' => ['count' => count($m), 'cash_out' => array_sum(array_column($m, 'cash_out_total')),
                    'this_week' => count(array_filter($m, fn ($c) => $c['matured_day'] >= $today - 7))],
                'profit' => ['week' => $week, 'last_week' => $lastWeek, 'change' => $week - $lastWeek],
                'players' => ['count' => $players, 'new_this_week' => $newPlayers],
            ],
        ];
    }

    /** A change in percent, or null without a base */
    private static function percent(float $value, ?float $base): ?int
    {
        return $base ? (int) round(($value / $base - 1) * 100) : null;
    }

    /** The banner in plain words: how busy yesterday was, and the clock when it is slowed */
    private static function bannerText(int $day, int $trades, ?float $norm, ?float $ratio, array $clock, int $normDays): string
    {
        $text = $norm === null
            ? number_format($trades) . " trades on game day $day."
            : number_format($trades) . " trades on game day $day, against about " . number_format($norm) . ' a day over the '
                . ($normDays === 1 ? 'game day' : $normDays . ' game days') . ' before' . ($ratio !== null && $ratio < self::QUIET ? ': the market is quiet.' : '.');
        if ($clock['slowed']) {
            $text .= ' The clock is slowed: a game day takes ' . self::seconds($clock['real_seconds_per_game_day'])
                . ' (usually ' . self::seconds($clock['target_real_seconds_per_game_day']) . ')';
            $behind = $clock['engine']['behind_seconds'];
            $text .= $behind !== null ? ', the engines are ' . number_format($behind) . ' real seconds behind' : '';
            $tax = $clock['processor_tax']['congestion'];
            $text .= $tax > 1 ? ', and processor tax is ' . rtrim(rtrim(number_format($tax, 1), '0'), '.') . ' times the usual.' : '.';
        }
        return $text;
    }

    /** Real seconds in words */
    private static function seconds(?int $s): string
    {
        if ($s === null) {
            return 'an unknown time';
        }
        return $s < 90 ? $s . ' real seconds' : ($s < 5400 ? round($s / 60) . ' real minutes' : round($s / 3600, 1) . ' real hours');
    }

    /**
     * Trades and credits by game day and place over the last DAYS game days:
     * the busiest PLACES places by name, the rest as "Elsewhere"
     */
    private static function activity(): array
    {
        [$epoch, $today] = self::today();
        $first = max(0, $today - self::DAYS + 1);
        $rows = DB::table('trades AS t')->leftJoin('objects AS o', 'o.object_id', '=', 't.location')
            ->where('t.game_t', '>=', $epoch + $first * 86400)->where('t.units', '>', 0)
            ->groupBy('day', 'o.object_name')
            ->selectRaw('FLOOR((t.game_t - ?) / 86400) AS day, o.object_name AS place, COUNT(*) AS trades, SUM(t.credits) AS credits', [$epoch])
            ->get();
        $byPlace = [];
        foreach ($rows as $r) {
            $byPlace[$r->place ?? 'Elsewhere'] = ($byPlace[$r->place ?? 'Elsewhere'] ?? 0) + (int) $r->credits;
        }
        arsort($byPlace);
        $named = array_slice(array_keys($byPlace), 0, self::PLACES);
        $places = $named;
        if (count($byPlace) > self::PLACES) {
            $places[] = 'Elsewhere';
        }
        $days = [];
        for ($d = $first; $d <= $today; $d++) {
            $days[$d] = ['day' => $d, 'trades' => 0, 'credits' => 0, 'by_place' => array_fill_keys($places, 0)];
        }
        foreach ($rows as $r) {
            $d = (int) $r->day;
            if (!isset($days[$d])) {
                continue;
            }
            $place = in_array($r->place, $named, true) ? $r->place : 'Elsewhere';
            $days[$d]['trades'] += (int) $r->trades;
            $days[$d]['credits'] += (int) $r->credits;
            $days[$d]['by_place'][$place] += (int) $r->credits;
        }
        // The world's first day with a trade: days before it don't count in the norm
        $firstTrade = DB::table('trades')->min('game_t');
        return ['places' => $places, 'days' => array_values($days),
            'first_day' => $firstTrade === null ? $today : max(0, intdiv((int) $firstTrade - $epoch, 86400))];
    }

    /**
     * Players' drones (the House's station drones aside): working and wrecks
     * by place, and how many worked a week ago (company_days). A closed
     * startup's drones leave the game and aren't counted as wrecks.
     */
    private static function drones(): array
    {
        $rows = World::playerDrones()->leftJoin('objects AS o', 'o.object_id', '=', 'a.object_id')
            ->groupBy('o.object_name')
            ->selectRaw('o.object_name AS place, SUM(a.wrecked_at IS NULL) AS working, SUM(a.wrecked_at IS NOT NULL) AS wrecks')
            ->get();
        $places = $rows->map(fn ($r) => ['place' => $r->place ?? 'Unknown', 'working' => (int) $r->working, 'wrecks' => (int) $r->wrecks])
            ->filter(fn ($r) => $r['working'] + $r['wrecks'] > 0)
            ->sortByDesc(fn ($r) => [$r['working'], $r['wrecks']])->values()->all();
        $weekAgo = null;
        $last = DB::table('company_days')->max('day');
        if ($last !== null && DB::table('company_days')->where('day', (int) $last - 7)->exists()) {
            $weekAgo = (int) DB::table('company_days')->where('day', (int) $last - 7)->sum('drones');
        }
        return ['working' => array_sum(array_column($places, 'working')), 'wrecks' => array_sum(array_column($places, 'wrecks')),
            'week_ago' => $weekAgo, 'places' => $places];
    }

    /** The matured companies still trading: id, name, owner, cash-out, when they matured */
    private static function matured(): array
    {
        [$epoch] = self::today();
        $rows = DB::table('companies AS c')->leftJoin('users AS u', 'u.user_id', '=', 'c.owner_id')
            ->where('c.startup', 0)->whereNotNull('c.owner_id')->whereNotNull('c.matured_t')->whereNull('c.eliminated_at')
            ->orderBy('c.company_id')->get(['c.company_id', 'c.name', 'c.matured_t', 'u.login']);
        $cashOuts = PlayerShares::cashOuts($rows->pluck('company_id')->all());
        return $rows->map(function ($c) use ($epoch, $cashOuts) {
                $cash = $cashOuts[(int) $c->company_id];
                return ['company' => (int) $c->company_id, 'name' => $c->name, 'owner' => $c->login,
                    'matured_day' => intdiv(max(0, (int) $c->matured_t - $epoch), 86400),
                    'cash_out_total' => (int) $cash['total'], 'cash_out' => (int) $cash['per_share']];
            })->all();
    }

    /**
     * Each matured company's earnings a game day over the last DAYS
     * (CompanyEarnings, as its page charts them), from the day it matured:
     * company => rows. The profit chart and the companies table are both
     * summed from these, so they agree (#716: the chart's 30 days came to
     * -49,440 and the table's to -36,683; the chart counted ended companies too)
     */
    private static function maturedDaily(array $matured): array
    {
        $out = [];
        foreach ($matured as $c) {
            $out[$c['company']] = array_map(function ($r) use ($c) {
                if ($r['day'] < $c['matured_day']) {
                    $r['sales'] = $r['purchases'] = $r['net'] = $r['tax'] = $r['trades'] = 0;
                }
                return $r;
            }, CompanyEarnings::daily($c['company'], self::DAYS));
        }
        return $out;
    }

    /**
     * The matured companies' combined profit each game day over the last
     * DAYS: sales after tax less purchases, from the day each matured. Only
     * the companies still trading, as the companies table lists them
     */
    private static function maturedProfit(array $daily): array
    {
        [, $today] = self::today();
        $first = max(0, $today - self::DAYS + 1);
        $rows = [];
        for ($d = $first; $d <= $today; $d++) {
            $rows[$d] = ['day' => $d, 'sales' => 0, 'purchases' => 0, 'net' => 0];
        }
        foreach ($daily as $days) {
            foreach ($days as $r) {
                if (isset($rows[$r['day']])) {
                    $rows[$r['day']]['sales'] += $r['sales'];
                    $rows[$r['day']]['purchases'] += $r['purchases'];
                    $rows[$r['day']]['net'] += $r['net'];
                }
            }
        }
        return array_values($rows);
    }

    /** The parts every figure is worked out from */
    private static function parts(): array
    {
        $matured = self::matured();
        $daily = self::maturedDaily($matured);
        return ['activity' => self::activity(), 'drones' => self::drones(), 'matured' => $matured,
            'daily' => $daily, 'profit' => self::maturedProfit($daily)];
    }

    /** Credits traded in each good over the last GOODS_DAYS game days, most first (top 8) */
    private static function goods(): array
    {
        $since = World::gameNow() - self::GOODS_DAYS * 86400;
        return DB::table('trades AS t')->leftJoin('resources AS r', 'r.id', '=', 't.resource')
            ->where('t.game_t', '>=', $since)->where('t.units', '>', 0)
            ->groupBy('t.resource', 'r.name')->orderByRaw('SUM(t.credits) DESC')->limit(8)
            ->get(['t.resource', 'r.name', DB::raw('SUM(t.credits) AS credits'), DB::raw('SUM(t.units) AS units'), DB::raw('COUNT(*) AS trades')])
            ->map(fn ($r) => ['resource' => (int) $r->resource, 'name' => $r->name ?? (string) $r->resource,
                'credits' => (int) $r->credits, 'units' => (int) $r->units, 'trades' => (int) $r->trades])->all();
    }

    /**
     * The best ask and best bid at each place for the main goods (the most
     * traded that have open orders, at most 6): from the order book, as the
     * Market page lists it
     */
    private static function spreads(array $goods): array
    {
        // Only orders whose drone is where it posted them, and not flying
        // (#716: a drone's buy order at Mars made a crossed book there while
        // it was on its way to Phobos)
        $asks = World::orders(4, 2000, true);
        $bids = World::orders(5, 2000, true);
        $book = [];
        foreach ([['ask', $asks], ['bid', $bids]] as [$side, $rows]) {
            foreach ($rows as $o) {
                $book[(int) $o['resource']]['name'] = $o['resource_name'];
                $book[(int) $o['resource']]['places'][$o['place'] ?? 'Unknown'][$side] = (int) $o['price'];
            }
        }
        $order = array_column($goods, 'resource');
        // The most traded first; then those with the most places quoting them
        $ids = array_keys($book);
        usort($ids, fn ($a, $b) => [array_search($a, $order) === false ? 99 : array_search($a, $order), -count($book[$b]['places'])]
            <=> [array_search($b, $order) === false ? 99 : array_search($b, $order), -count($book[$a]['places'])]);
        $out = [];
        foreach (array_slice($ids, 0, 6) as $id) {
            $places = [];
            foreach ($book[$id]['places'] as $place => $p) {
                $places[] = ['place' => $place, 'ask' => $p['ask'] ?? null, 'bid' => $p['bid'] ?? null];
            }
            usort($places, fn ($a, $b) => strcmp($a['place'], $b['place']));
            $out[] = ['resource' => $id, 'name' => $book[$id]['name'], 'places' => $places];
        }
        return $out;
    }

    /**
     * Every matured company, as a buyer of its shares would judge it: a
     * share's cash-out value, working drones, profit and dividends a share
     * over 30 game days, the yield (dividends against cash-out) and its
     * daily profit, from the same earnings as the profit chart (#716)
     */
    private static function companies(array $matured, array $daily): array
    {
        [, $today] = self::today();
        $out = [];
        $dividends = PlayerShares::dividends30Many(array_column($matured, 'company'));
        foreach ($matured as $c) {
            $days = array_values(array_filter($daily[$c['company']] ?? [], fn ($r) => $r['day'] > $today - 30));
            $div = $dividends[$c['company']];
            $out[] = [
                'company' => $c['company'], 'name' => $c['name'], 'owner' => $c['owner'],
                'cash_out' => $c['cash_out'],
                'drones' => (int) World::playerDrones()->where('a.company_id', $c['company'])->whereNull('a.wrecked_at')->count(),
                'profit30' => array_sum(array_column($days, 'net')),
                'dividends30' => $div,
                'yield_percent' => $c['cash_out'] > 0 ? round(100 * $div / $c['cash_out'], 1) : null,
                'daily_profit' => array_column($days, 'net'),
            ];
        }
        usort($out, fn ($a, $b) => $b['cash_out'] <=> $a['cash_out']);
        return $out;
    }

    /** Each player's wealth: shares at cash-out, credits, and what the bank owes them (the 25 richest) */
    private static function players(): array
    {
        // The public's players, as /players lists them (#716: a class
        // group's accounts showed here and not there)
        $w = array_values(PlayerBoards::wealths(null));
        usort($w, fn ($a, $b) => $b['wealth'] <=> $a['wealth']);
        return array_map(fn ($p) => ['name' => $p['name'], 'shares' => $p['shares'], 'credits' => $p['credits'],
            'bank_due' => $p['bank_due'], 'wealth' => $p['wealth']], array_slice($w, 0, 25));
    }

    /** The whole dashboard */
    private static function build(): array
    {
        $parts = self::parts();
        $goods = self::goods();
        return self::summary($parts) + [
            'trading' => ['places' => $parts['activity']['places'], 'days' => $parts['activity']['days']],
            'matured_profit' => $parts['profit'],
            'goods' => $goods,
            'spreads' => self::spreads($goods),
            'news' => array_slice(HouseMarket::news(), 0, 10),
            'companies' => self::companies($parts['matured'], $parts['daily']),
            'drones_by_place' => $parts['drones']['places'],
            'players' => self::players(),
            'sources' => [
                'trading' => 'GET /api/v1/history, summed by game day and place',
                'matured_profit' => 'GET /api/v1/companies/{id}/earnings for each matured company, summed',
                'goods' => 'GET /api/v1/history?days=' . self::GOODS_DAYS . ', summed by good',
                'spreads' => 'GET /api/v1/orders?side=sell and ?side=buy, the best price for each good and place',
                'news' => 'GET /api/v1/news',
                'companies' => 'GET /api/v1/shares, /player-shares/{id} and /companies/{id}/earnings',
                'drones_by_place' => 'GET /api/v1/places (station drones and closed startups left out)',
                'players' => 'GET /api/v1/players',
            ],
        ];
    }
}
