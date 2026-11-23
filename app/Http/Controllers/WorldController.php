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
    public const PREMIUM_DAYS = \App\Services\PlayerShares::PREMIUM_DAYS;

    /** Companies the stock exchange board shows on a phone before "Show all" (#884) */
    public const FIRST_ON_PHONE = 15;

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
        // What a startup can trade at (#880): the House's prices in the deal column
        $startupView = $request->boolean('startup');
        return view('world.market', compact('rows', 'filter', 'counts', 'places', 'goods', 'trades', 'fuel', 'startupView'));
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
        // The chart opens on the first of them, so fuel, whose price barely moves, goes after the
        // most traded good (#885: it opened on a flat line at 3)
        $fuel = $resources->firstWhere('id', \App\Services\Release::FUEL);
        if ($fuel && $resources->count() > 1 && (int) $resources->first()->id === \App\Services\Release::FUEL) {
            $resources = $resources->reject(fn ($r) => (int) $r->id === \App\Services\Release::FUEL)->values();
            $resources->splice(1, 0, [$fuel]);
        }
        // Each place keeps one colour: the places trades happen at, in ID order
        ['places' => $places, 'today' => $today] = \App\Services\MarketHistory::frame();
        return view('world.history', compact('resources', 'places', 'today'));
    }

    /** The stock exchange (#484): every company's shares, what's for sale, and the latest trades */
    public function shares()
    {
        return view('world.shares', self::sharesPage());
    }

    /** Real seconds after which a view of the stock exchange starts a rebuild of it (#726) */
    public const SHARES_TTL = 60;

    /**
     * Everything the stock exchange page shows (#726: worked out on each
     * view, it took 4.5 to 7 real seconds on the live database): the last
     * one worked out, rebuilt by the scheduler once SHARES_TTL old (Kept)
     */
    public static function sharesPage(): array
    {
        return \App\Services\Kept::get(self::sharesKey(), self::SHARES_TTL, fn () => self::sharesWork());
    }

    /** Where the stock exchange page is kept (one copy under each set of rules) */
    public static function sharesKey(): string
    {
        return 'market:shares:' . (int) (bool) config('forge.new_rules');
    }

    /** The stock exchange page's figures, worked out now */
    public static function sharesWork(): array
    {
        $newRules = (bool) config('forge.new_rules');
        return [
            'exchange' => \App\Services\Shares::exchange(),
            // The new rules (#666): matured companies' shares, which players
            // trade, a row a company (#699), best yield first (#716)
            'playerMarket' => $newRules ? \App\Services\PlayerShares::board() : null,
            'playerTrades' => $newRules ? \App\Services\PlayerShares::trades() : [],
            // When it was worked out, shown on the page (#738)
            'asOf' => World::gameNow(),
        ];
    }

    /** The economy dashboard (#698): is the market busy, are companies making money, who is winning */
    public function dashboard()
    {
        // The world's events, marked on its day charts (#720)
        return view('world.dashboard', ['dash' => \App\Services\Dashboard::full(), 'marks' => \App\Services\WorldEvents::marks()]);
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
        // Only companies founded under the startup rules (#882), as a startup's rank counts them
        $startupsOnly = $request->boolean('startups');
        $atAge = $age === null ? null : Leaderboard::atAge($age, 100, $board, $startupsOnly);
        $allTime = Leaderboard::allTime(50, $maxAge);
        $rising = Leaderboard::rising(50, $maxAge);
        $hasAWeek = Leaderboard::hasAWeek();
        $kit = Leaderboard::kitValue(Leaderboard::prices());
        return view('world.leaderboard', compact('allTime', 'rising', 'hasAWeek', 'kit', 'maxAge', 'age', 'atAge', 'startupsOnly'));
    }

    /** The lineage map (#618): every release and push, with the earlier code each carries */
    public function lineage()
    {
        return view('world.lineage', ['graph' => \App\Services\CodeLineage::graph()]);
    }

    /** The tech map (#804): what each thing is made from, with what tool, and how */
    public function technology()
    {
        return view('world.technology', ['map' => \App\Services\TechMap::build()]);
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

    public function map(\Illuminate\Http\Request $request)
    {
        $planets = World::map();
        $places = World::places();
        $farMap = \App\Services\FarMap::seasons();   // #922
        // A resource's heat map over it (#952): ?resource=ID, &season=next
        $options = \App\Services\ResourceMap::options();
        $chosen = (int) $request->query('resource', 0);
        $heat = in_array($chosen, array_column($options, 'resource'), true)
            ? \App\Services\ResourceMap::build($chosen, $request->query('season') === 'next') : null;
        return view('world.map', compact('planets', 'places', 'farMap', 'options', 'heat'));
    }
}
