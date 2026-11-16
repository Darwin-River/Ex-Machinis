<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

use App\Services\Leaderboard;
use App\Services\World;

/**
 * The Market and Map pages (#324 step 17); the Home page's feed is in
 * HomeController
 */
class WorldController extends Controller
{
    /** Game days a players' trade stays recent enough to set a premium or discount against cash-out (#716) */
    public const PREMIUM_DAYS = 7;

    /**
     * The Market page (#721): the goods board, one row per good, and where
     * fuel is on sale. Round 70: it was 28,300 pixels of order tables. A
     * good's whole order book is on its own page; the House's demand and
     * supply on /market/house. Old links with ?resource= go to the good's
     * page, and ?house= or ?supply= to the House page
     */
    public function market(\Illuminate\Http\Request $request)
    {
        if (is_numeric($request->query('resource'))) {
            return redirect('/resources/' . (int) $request->query('resource') . '#order-book', 301);
        }
        if ($request->hasAny(['house', 'supply'])) {
            return redirect('/market/house?' . http_build_query($request->only(['house', 'supply'])), 301);
        }
        $filter = (string) $request->query('show', 'traded');
        $filter = array_key_exists($filter, \App\Services\MarketBoard::FILTERS) ? $filter : 'traded';
        $all = \App\Services\MarketBoard::all();
        $rows = \App\Services\MarketBoard::filtered($filter);
        // How many goods each chip shows
        $counts = [];
        foreach (array_keys(\App\Services\MarketBoard::FILTERS) as $f) {
            $counts[$f] = count(\App\Services\MarketBoard::filtered($f));
        }
        // Each place keeps one colour: the places on the board, in ID order
        $places = collect($all)->flatMap(fn ($r) => $r['places'])->pluck('place', 'place_id')->sortKeys()->all();
        $goods = collect($all)->pluck('name', 'resource')->sort()->all();
        $trades = World::trades(10);
        $fuel = World::fuelForSale();
        return view('world.market', compact('rows', 'filter', 'counts', 'places', 'goods', 'trades', 'fuel'));
    }

    /** The House's demand and supply (#550, #630), moved off the Market page (#721) */
    public function house(\Illuminate\Http\Request $request)
    {
        // The House's demand (#550): one good everywhere, or the bids closest to full
        $houseResource = is_numeric($request->query('house')) ? (int) $request->query('house') : null;
        $demand = \App\Services\HouseMarket::demand($houseResource);
        $houseGoods = collect($demand)->pluck('resource_name', 'resource')->sort()->all();
        if ($houseResource === null) {
            usort($demand, fn ($a, $b) => [$a['left'] / $a['daily'], $a['resource']] <=> [$b['left'] / $b['daily'], $b['resource']]);
            $demand = array_slice($demand, 0, 30);
        }
        // What the House sells, and when it restocks (#630): one good everywhere, or the goods closest to sold out
        $supplyResource = is_numeric($request->query('supply')) ? (int) $request->query('supply') : null;
        $supply = \App\Services\HouseMarket::supply($supplyResource);
        $supplyGoods = collect($supply)->pluck('resource_name', 'resource')->sort()->all();
        if ($supplyResource === null) {
            usort($supply, fn ($a, $b) => [$a['on_sale'] / $a['refill_to'], $a['resource']] <=> [$b['on_sale'] / $b['refill_to'], $b['resource']]);
            $supply = array_slice($supply, 0, 30);
        }
        return view('world.house', compact('demand', 'houseGoods', 'houseResource', 'supply', 'supplyGoods', 'supplyResource'));
    }

    /** Market history (#380): charts of prices and volumes, drawn from /api/v1/history */
    public function history()
    {
        // The resources traded in the last year, most credits first: the choices
        $resources = DB::table('trades AS t')->join('resources AS r', 'r.id', '=', 't.resource')
            ->where('t.game_t', '>=', World::gameNow() - 365 * 86400)->groupBy('t.resource', 'r.name')
            ->orderByRaw('SUM(t.credits) DESC')->get(['t.resource AS id', 'r.name']);
        // Each place keeps one colour: the places trades happen at, in ID order
        ['places' => $places, 'today' => $today] = \App\Services\MarketHistory::frame();
        return view('world.history', compact('resources', 'places', 'today'));
    }

    /** The stock exchange (#484): every company's shares, what's for sale, and the latest trades */
    public function shares()
    {
        $exchange = \App\Services\Shares::exchange();
        // The new rules (#666): matured companies' shares, which players trade
        $playerMarket = config('forge.new_rules') ? \App\Services\PlayerShares::market() : null;
        if ($playerMarket) {
            // One row a company (#699): a share's cash-out over 30 game days,
            // its dividends and yield, the last price players paid against
            // cash-out, and whether it has stalled
            $today = \App\Services\MarketHistory::today();
            $hasCash = \Illuminate\Support\Facades\Schema::hasColumn('company_days', 'cash_out');
            $now = World::gameNow();
            foreach ($playerMarket as &$m) {
                $id = $m['company'];
                $m['dividends30'] = $m['sells_at'] - $m['buys_at'];
                $m['yield_percent'] = $m['buys_at'] > 0 ? round(100 * $m['dividends30'] / $m['buys_at'], 1) : null;
                $m['spark'] = $hasCash ? DB::table('company_days')->where('company_id', $id)->where('day', '>', $today - 30)
                    ->whereNotNull('cash_out')->orderBy('day')->get(['day', 'cash_out'])
                    ->map(fn ($r) => ['day' => (int) $r->day, 'v' => intdiv(max(0, (int) $r->cash_out), \App\Services\PlayerShares::COUNT)])->all() : [];
                // The last trade between two players (#716): the bank's trades
                // are at its own prices, and an old trade says little about
                // today's, so the premium is shown only for a recent one
                $last = DB::table('player_share_trades')->where('company_id', $id)->whereNotNull('seller_id')
                    ->whereNotNull('buyer_id')->orderByDesc('id')->first(['price', 'game_t']);
                $m['last_price'] = $last ? (int) $last->price : null;
                $m['last_days_ago'] = $last ? intdiv(max(0, $now - (int) $last->game_t), 86400) : null;
                $m['premium_percent'] = $last && $m['buys_at'] > 0 && $m['last_days_ago'] <= self::PREMIUM_DAYS
                    ? (int) round(100 * ($last->price - $m['buys_at']) / $m['buys_at']) : null;
                $h = \App\Services\CompanyHealth::of($id);
                $m['stalled'] = (bool) $h['stalled'];
                $m['at_risk'] = (bool) $h['at_risk'];
                $m['failing'] = $h['failing_reason'] !== null;
                $m['health_note'] = $h['stalled'] ? $h['stalled_reason'] : ($h['at_risk'] ? $h['at_risk_reason'] : $h['failing_reason']);
            }
            unset($m);
            // Best yield first (#716: the table was alphabetical); no yield last
            usort($playerMarket, fn ($a, $b) => [$b['yield_percent'] === null ? 0 : 1, $b['yield_percent'] ?? 0, $a['name']]
                <=> [$a['yield_percent'] === null ? 0 : 1, $a['yield_percent'] ?? 0, $b['name']]);
        }
        $playerTrades = config('forge.new_rules') ? \App\Services\PlayerShares::trades() : [];
        return view('world.shares', compact('exchange', 'playerMarket', 'playerTrades'));
    }

    /** The economy dashboard (#698): is the market busy, are companies making money, who is winning */
    public function dashboard()
    {
        return view('world.dashboard', ['dash' => \App\Services\Dashboard::full()]);
    }

    /** The leaderboard (#324 step 18): net wealth, all time and Rising */
    public function leaderboard(\Illuminate\Http\Request $request)
    {
        Leaderboard::snapshotIfDue();
        // Only companies up to this many game days old (#548): kept for old links
        $maxAge = is_numeric($request->query('max_age')) ? max(0, (float) $request->query('max_age')) : null;
        // Every company at the same age (#614), from its history
        $age = is_numeric($request->query('age')) ? min(100000, max(0, round((float) $request->query('age'), 1))) : null;
        $board = Leaderboard::wealth();
        $atAge = $age === null ? null : Leaderboard::atAge($age, 100, $board);
        $allTime = Leaderboard::allTime(50, $maxAge);
        $rising = Leaderboard::rising(50, $maxAge);
        $hasAWeek = Leaderboard::hasAWeek();
        $kit = Leaderboard::kitValue(Leaderboard::prices());
        return view('world.leaderboard', compact('allTime', 'rising', 'hasAWeek', 'kit', 'maxAge', 'age', 'atAge'));
    }

    /** The lineage map (#618): every release and push, with the earlier code each carries */
    public function lineage()
    {
        return view('world.lineage', ['graph' => \App\Services\CodeLineage::graph()]);
    }

    /** The game's queries: how many parameters each takes (#380) */
    public function machineQueries()
    {
        return response()->json(\App\Services\LiveQuery::catalog());
    }

    /** The game's protocols: how many parameters each takes (#380) */
    public function machineProtocols()
    {
        return response()->json(\App\Services\LiveQuery::protocols());
    }

    /**
     * Runs one of the game's queries as a drone would, for the Machine
     * page's live data (#380): the rows as the bytes a drone's memory gets
     */
    public function machineQuery(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer', 'drone' => 'required|integer|min:1', 'size' => 'integer|min:0|max:65535',
            'v' => 'array|max:9', 'v.*' => 'integer|min:-32768|max:65535', 's' => 'array|max:4', 's.*' => 'nullable|string|max:255',
        ]);
        if (!DB::table('agents')->where('agent_id', $data['drone'])->exists()) {
            return response()->json(['error' => 'There is no drone ' . $data['drone'] . '.'], 422);
        }
        return response()->json(\App\Services\LiveQuery::run((int) $data['id'], (int) $data['drone'], (int) ($data['size'] ?? 65535),
            array_map('intval', $data['v'] ?? []), $data['s'] ?? []));
    }

    public function map()
    {
        $planets = World::map();
        $places = World::places();
        return view('world.map', compact('planets', 'places'));
    }
}
