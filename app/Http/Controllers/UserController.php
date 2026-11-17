<?php

namespace App\Http\Controllers;

use App\Agent;
use App\User;
use App\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class UserController extends Controller
{

    /**
     * Displays companies search engine
     */
    public function companyIndex()
    {
        return view('user.companies');
    }

    /**
     * Used by search engine to retrieve companies (users actually)
     * @param $request Request object
     * @return JsonResponse
     */
    public function companySearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');
        // Companies (#324 step 4); the JSON keeps the user_id key for the page's links
        // Age in game days, not real ones (#692: a 4-day-old company read "4")
        $now = (int) \App\Services\World::gameNow();
        $query = DB::table('companies')->select('companies.company_id AS user_id', 'companies.name AS name',
                // An ended company's age stops when it ended (#716)
                DB::raw("ROUND(GREATEST(0, LEAST($now, IFNULL(companies.eliminated_at, $now)) - IFNULL(companies.founded_t, $now)) / 86400, 1) AS age"),
                // Its working drones, and its wrecks apart (#726: Bank Maker read
                // 106 spacecraft, wrecks and all, where the leaderboard said 8)
                DB::raw('COUNT(agents.agent_id) - COUNT(agents.wrecked_at) AS agents'), DB::raw('COUNT(agents.wrecked_at) AS wrecks'),
                DB::raw('IFNULL(companies.credits,0) AS credits'),
                DB::raw('companies.eliminated_at IS NOT NULL AS ended'), 'companies.seedling', 'companies.matured_t')
            ->leftJoin('agents', fn ($j) => $j->on('agents.company_id', '=', 'companies.company_id')->whereNull('agents.station_id'))
            // The House runs the exchanges' station drones: it isn't a company to look up (#716)
            ->where('companies.house', 0)->groupBy('companies.company_id');
        if ($request->get('user_id') !== null)
            $query->where('companies.company_id', intval($request->get('user_id')));
        if ($request->get('name') !== null)
            $query->where('companies.name', 'LIKE', "%" . $request->get('name') . "%");
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else {
            // Working companies first, then those that ended (#692)
            $query->orderBy('ended')->orderBy('companies.company_id');
        }
        $spaceObjects = $query->paginate($resultsPerPage);
        // Credits with thousands separators, and an ended company marked (#692)
        $spaceObjects->getCollection()->transform(function ($c) {
            $c->credits = number_format((int) $c->credits);
            $c->ended = (bool) $c->ended;
            // Its status in a column of its own (#744), not "(ended)" on its name
            $c->status = $c->ended ? 'ended' : ((int) $c->seedling === 1 ? 'seedling' : ($c->matured_t !== null ? 'matured' : 'released'));
            unset($c->seedling, $c->matured_t);
            return $c;
        });
        // var_dump($spaceObjects);exit;

        return response()->json($spaceObjects, 200);
    }

    /**
     * Displays single company
     * @param int $id company id
     * @return View
     */
    public function companyView($id)
    {
        $company = Company::where('company_id', '=', $id)->firstOrFail();
        // The view shows the company's ID, name, start date and bank account
        $user = (object) ['user_id' => $company->company_id, 'name' => $company->name,
            'created_at' => $company->created_at, 'credits' => $company->credits,
            // The new rules (#637): a seedling's debt, or when it matured
            'seedling' => (int) ($company->seedling ?? 0), 'debt' => (int) ($company->debt ?? 0),
            'matured_t' => $company->matured_t ?? null, 'owner_id' => $company->owner_id ?? null,
            'eliminated_at' => $company->eliminated_at ?? null, 'end_cause' => $company->end_cause ?? null,
            'founded_t' => $company->founded_t ?? null];

        $spacecraft = Agent::with('hull')->with('location')->where('company_id', '=', $id)->get();

        // The stock market (#471)
        $shares = \App\Services\Shares::summary((int) $company->company_id);
        $shares['compute'] = \App\Services\ProcessorTax::of((int) $company->company_id);
        $shares['health'] = \App\Services\CompanyHealth::of((int) $company->company_id)
            + ['drones' => \App\Services\CompanyHealth::drones((int) $company->company_id)];
        // Its place among every company at its age (#548, #614),
        // and its net wealth, the game's score, at the top of the page (#591)
        $board = \App\Services\Leaderboard::wealth();
        $rank = \App\Services\Leaderboard::peerRank((int) $company->company_id, $board);
        $stats = ['wealth' => $board[$company->company_id]['wealth'] ?? null,
            // An ended company's age stops when it ended (#716)
            'age' => \App\Services\Leaderboard::age(\Illuminate\Support\Facades\DB::table('companies')
                ->where('company_id', $company->company_id)->value('founded_t'),
                min(\App\Services\World::gameNow(), (int) ($company->eliminated_at ?? PHP_INT_MAX))),
            'working' => $spacecraft->whereNull('wrecked_at')->count(), 'drones' => $spacecraft->count()];
        // Code pushes (#553)
        $pushes = \App\Services\CodePush::summary(\Illuminate\Support\Facades\DB::table('companies')
            ->where('company_id', $company->company_id)->first());

        // Its code and revisions (#594): the drones' pages link here
        $code = \App\Services\CompanyCode::of((int) $company->company_id);
        // Its code's lineage (#616): parents, new code, and who carries it on
        $lineage = \App\Services\CodeLineage::ofCompany((int) $company->company_id);

        // Today's game day, for its share price chart (#600)
        $today = \App\Services\MarketHistory::today();

        // The new rules (#638): a matured company's shares, the bank's prices and the players' book
        $playerShares = null;
        if (config('forge.new_rules') && $company->owner_id !== null && (int) $company->seedling === 0 && $company->matured_t !== null) {
            $viewer = \Illuminate\Support\Facades\Auth::user();
            // One cash-out for the page: its parts, the bank's prices and the
            // credits tile (#716: the Shares paragraph said 12,304, the tile 11,292)
            $cash = \App\Services\PlayerShares::cashOut((int) $id);
            $user->credits = $cash['credits'];
            $playerShares = \App\Services\PlayerShares::prices((int) $id, $cash) + [
                'book' => \App\Services\PlayerShares::book((int) $id),
                'held' => $viewer && $viewer->login !== null ? \App\Services\PlayerShares::held((int) $viewer->user_id, (int) $id) : null,
                'cash_out' => $cash,
                // New shares to fund growth (#780)
                'count' => \App\Services\PlayerShares::count((int) $id),
                'offering' => \App\Services\PlayerShares::offering((int) $id),
                'is_owner' => $viewer && (int) $viewer->user_id === (int) $company->owner_id,
            ];
        }
        // The new rules (#639): who it deals with, same-owner deals flagged
        $dealings = config('forge.new_rules') ? \App\Services\Dealings::of((int) $id) : null;
        // Its owner, for the title (#697)
        $owner = $company->owner_id !== null
            ? \Illuminate\Support\Facades\DB::table('users')->where('user_id', $company->owner_id)->value('login') : null;
        // A live seedling's road to maturity (#697): its credits against its
        // debt, the mark it matures at and its credit cap, and what its owner can do
        $road = null;
        if ((int) ($company->seedling ?? 0) === 1 && $company->eliminated_at === null) {
            $row = \Illuminate\Support\Facades\DB::table('companies')->where('company_id', $id)->first();
            $viewer = \Illuminate\Support\Facades\Auth::user();
            $debt = (int) $row->debt;
            $road = ['credits' => (int) $row->credits, 'debt' => $debt, 'owed_tax' => (int) $row->compute_debt,
                'mature_at' => $debt + \App\Services\Seedlings::matureMargin(), 'margin' => \App\Services\Seedlings::matureMargin(),
                'cap' => \App\Services\Seedlings::creditCap($row), 'can_mature' => \App\Services\Seedlings::canMature($row),
                'push_fee' => \App\Services\Seedlings::pushFee($row),
                'owner' => $viewer && $viewer->login !== null && $row->owner_id !== null && (int) $viewer->user_id === (int) $row->owner_id];
            // When it should mature at its pace (#744: a projected maturing day)
            $road['forecast'] = \App\Services\CompanyNow::forecast($row, $road['mature_at'] + $road['owed_tax'] - (int) $row->compute_debt,
                (int) $row->credits - (int) $row->compute_debt, $road['can_mature']);
        }
        // Its performance on one game-day axis (#699): net wealth and credits,
        // cash-out a share against the bank's price and players' trades,
        // earnings, dividends and working drones
        $epoch = (int) (\Illuminate\Support\Facades\DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $matured = $company->owner_id !== null && (int) $company->seedling === 0 && $company->matured_t !== null;
        $perf = [
            'today' => $today,
            'history' => \App\Services\Leaderboard::history((int) $id),
            'earnings' => \App\Services\CompanyEarnings::daily((int) $id, \App\Services\CompanyEarnings::MAX_DAYS),
            'shares' => $matured || config('forge.new_rules') ? \App\Services\PlayerShares::count((int) $id) : max(1, (int) $shares['count']),
            'matured' => $matured,
            // Trades between players only (#716: the bank's, at its own prices, were dots too)
            'trades' => $matured ? array_values(array_map(fn ($t) => ['day' => intdiv(max(0, $t['game_time'] - $epoch), 86400), 'price' => $t['price'],
                'units' => $t['units'], 'seller' => $t['seller_name'], 'buyer' => $t['buyer_name']],
                array_filter(\App\Services\PlayerShares::trades((int) $id, 500), fn ($t) => !$t['seller_is_bank'] && !$t['buyer_is_bank']))) : [],
            // The first game day the world recorded cash-out (#716: "from the day a
            // company matures" wasn't so: it began on one day for every company)
            'cash_out_from' => \Illuminate\Support\Facades\Schema::hasColumn('company_days', 'cash_out')
                ? \Illuminate\Support\Facades\DB::table('company_days')->whereNotNull('cash_out')->min('day') : null,
            // The world's events and its own, marked on every chart (#720)
            'marks' => \App\Services\WorldEvents::marks((int) $id),
        ];
        if ($playerShares) {
            // Cash-out a share and its dividends over 30 game days, at the top (#699)
            $stats['cash_out'] = $playerShares['buys_at'];
            $stats['dividends30'] = \App\Services\PlayerShares::dividends30((int) $id);
        }
        return view('user.company', compact('user', 'spacecraft', 'shares', 'rank', 'pushes', 'stats', 'code', 'today', 'lineage',
            'playerShares', 'dealings', 'owner', 'road', 'perf'));
    }

    /** Pushes new code to a company's working drones, with its password (#553) */
    public function push(\Illuminate\Http\Request $request, $id)
    {
        $request->validate(['password' => 'required|string|max:200',
            'script' => 'required|string|max:' . config('forge.max_script_length')]);
        $script = str_replace("\r\n", "\n", (string) $request->input('script'));
        $result = \App\Services\CodePush::push((int) $id, (string) $request->input('password'), $script);
        if (!$result['ok']) {
            return back()->withErrors(['push' => $result['error']])->withInput($request->except('password'));
        }
        return redirect('/companies/' . (int) $id . '#pushes')->withSuccess('Push ' . $result['number'] . ' is on its way to '
            . count($result['drones']) . ' drone(s) at the speed of light, for ' . number_format($result['fee'])
            . ' credits. Each restarts its launched function on the new code when it arrives.');
    }

}
