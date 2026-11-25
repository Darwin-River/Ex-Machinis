<?php

namespace App\Http\Controllers;

use App\Agent;
use App\Company;
use App\Query;
use App\Resource;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

class HomeController extends Controller
{
    /** The latest events the events page searches and lists (#726) */
    public const EVENTS_WINDOW = 500;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //  $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // The game clock, live (#324 step 20)
        $clock = \App\Services\LaunchQueue::clock();
        if (config('forge.new_rules')) {
            // The home page for the new rules (#697): the pitch, the four
            // steps with the numbers in force, the economy at a glance (#698)
            // and a short feed with fuel purchases grouped
            $startup = ['grant' => \App\Services\Startups::grant(),
                'mature_at' => \App\Services\Startups::grant() + \App\Services\Startups::matureMargin(),
                'margin' => \App\Services\Startups::matureMargin(), 'shares' => \App\Services\Startups::SHARES,
                'credits' => (int) config('forge.starting_credits'), 'hull' => (int) config('forge.startup_hull_value'),
                'fee' => (int) config('forge.startup_fee')];
            // Step 0 is watching (#1049): the game in action, live, before any code
            if (!auth()->check()) {
                session([\App\Services\Pipeline::WATCHED => true]);   // step 1 of the ladder (#1043)
            }
            $full = \App\Services\Dashboard::full();
            return view('home-new-rules', ['feed' => \App\Services\World::feedGrouped(8), 'clock' => $clock,
                'status' => \App\Services\LaunchQueue::status(), 'startup' => $startup,
                'dash' => \App\Services\Dashboard::compact(), 'map' => \App\Services\LiveMap::now(),
                'trading' => ($full['trading'] ?? ['places' => [], 'days' => []]) + ['today' => $full['today'] ?? null]]);
        }
        // What's happening in the world (#324 step 17)
        $feed = \App\Services\World::feed();
        return view('home', compact('feed', 'clock'));
    }

    /**
     * The scheduler's tick, every few seconds (#367 step 5: it read the
     * game's mailboxes before email was removed): the leaderboard's hourly
     * wealth snapshot (#324 step 18), and launching releases that waited
     * for room (#324 step 20).
     */
    public function tick($key)
    {
        $this->requireCronKey($key);
        \App\Services\Leaderboard::snapshotIfDue();
        \App\Services\LaunchQueue::drain();
        // Markets that move (#380 round 36): the House's orders at today's
        // prices
        \App\Services\HouseMarket::tick();
        // The new rules (#638): the bank pays for shares sold to it when due,
        // and matured companies pay their shareholders dividends
        if (config('forge.new_rules')) {
            \App\Services\PlayerShares::settleBankPayments();
            // The bank's shares sell into players' bids above close-out (#928)
            \App\Services\PlayerShares::bankSells();
            \App\Services\PlayerShares::payDividends();
            // ...and pay out what their code asked to (#930)
            \App\Services\PlayerShares::settlePayouts();
            \App\Services\PlayerBoards::startDay();
            \App\Services\PlayerShares::dropEndedOrders();
            // A matured company that sells nothing for 60 game days is wound up (#875)
            \App\Services\WindUp::runIfDue();
        }
        // A company whose code called close_company() ends (#925)
        \App\Services\Closures::settle();
        // Loans (#928): the code's cancels and repayments, and loans due
        \App\Services\Loans::settle();
        // Contracts (#928): cancels, and contracts due or fully delivered
        \App\Services\Contracts::settle();
        // The home page's replay (#1042): recorded once, while there is none
        if (config('forge.new_rules')) {
            \App\Services\Replay::recordIfMissing();
        }
        // Badges (#1048): an award pass every 15 real minutes
        \App\Services\Badges::awardDue();
        return response('ok');
    }

    /** The scheduler's House loop (#764): the House's orders, every real second */
    public function house($key)
    {
        $this->requireCronKey($key);
        // While it runs, the tick leaves the House to it
        \Illuminate\Support\Facades\Cache::put('house-loop-alive', 1, 30);
        return response((string) \App\Services\HouseMarket::refreshNow());
    }

    /**
     * The scheduler's keeper (#738): rebuilds each kept figure (the
     * dashboard, the stock exchange) once it is its TTL old, so a page view
     * never waits for one. Called once a real minute, apart from the tick,
     * since a rebuild can take a minute or more on the live database
     */
    public function keep($key)
    {
        $this->requireCronKey($key);
        @set_time_limit(600);
        $done = \App\Services\Kept::keepAll();
        // Pegged share orders follow the bank's prices (#766)
        if (config('forge.new_rules')) {
            $done['pegged_orders_moved'] = \App\Services\PlayerShares::repeg();
            // Players' trading scripts (#768), each at most every forge.trade_script_every real seconds
            $done['trade_scripts_run'] = \App\Services\TradeScripts::runAll();
            // Opt-in e-mail notices (#761), every few real minutes
            $done['notices'] = \App\Services\Notices::runIfDue();
        }
        // The game's history within the disk budget (#813)
        $done['history'] = \App\Services\HistoryBudget::pruneIfDue();
        // A table holding far more free space than rows hands it back (#827)
        $done['history_rebuilt'] = \App\Services\HistoryBudget::rebuildIfDue();
        // Finished actions go after house_rules.actions_keep_days game days (#944)
        $done['actions_pruned'] = \App\Services\ActionsPrune::runIfDue();
        return response()->json($done);
    }

    /** Where the players' manual and its images live */
    private const PLAYERS_MANUAL = 'documents/players-manual.md';
    private const MANUAL_IMAGES = 'documents/images';
    private const REPOSITORY_DOCS = 'https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/';
    /** Part of the cached manual's and blog posts' keys: change it when renderPlayersManual changes (#848: Lua in colour) */
    public const RENDER_VERSION = 2;

    /**
     * The players' manual (documents/players-manual.md) at /manual, rendered
     * from the Markdown so the page never drifts from it. It was Getting
     * Started until #719
     */
    public function playersManual()
    {
        $manual = self::manualHtml();
        return view('pages.players-manual', compact('manual'));
    }

    /** The manual, rendered once for each version of the file */
    private static function manualHtml(): string
    {
        return Cache::rememberForever('players-manual:' . self::RENDER_VERSION . ':' . filemtime(base_path(self::PLAYERS_MANUAL)),
            // One title (#744): the page's header, not the file's own heading too
            fn () => preg_replace('/<h1\b[^>]*>.*?<\/h1>/s', '', self::renderPlayersManual(file_get_contents(base_path(self::PLAYERS_MANUAL))), 1));
    }

    /** The IDs of the manual's headings and anchors, for old /getting-started#anchor links */
    public static function manualAnchors(): array
    {
        preg_match_all('/<(?:h[1-6]|a) id="([A-Za-z0-9_-]+)"/', self::manualHtml(), $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Getting started (#719): your first startup in five minutes, with the
     * numbers in force. Round 70: both home-page buttons led to the whole
     * manual, 80,000 pixels that opened with a changelog from 2020
     */
    public function firstStartup()
    {
        $grant = \App\Services\Startups::grant();
        $startup = ['grant' => $grant, 'margin' => \App\Services\Startups::matureMargin(),
            'mature_at' => $grant + \App\Services\Startups::matureMargin(), 'shares' => \App\Services\Startups::SHARES,
            'credits' => (int) config('forge.starting_credits'), 'hull' => (int) config('forge.startup_hull_value'),
            'fee' => (int) config('forge.startup_fee'), 'push_fee' => (int) config('forge.startup_push_fee'),
            // The road's scale: a little past the mark it matures at (the credit cap is retired, #923)
            'cap' => (int) ceil(1.15 * ($grant + \App\Services\Startups::matureMargin())) ?: 1, 'max' => (int) config('forge.startup_max')];
        $starters = \App\Services\Release::STARTERS;
        unset($starters['trader']);   // a startup can't trade with other drones (#658)
        $clock = \App\Services\LaunchQueue::clock();
        $anchors = self::manualAnchors();
        return view('pages.first-startup', compact('startup', 'starters', 'clock', 'anchors'));
    }

    /** The manual's images moved with it (#719) */
    public function oldManualImage(string $file)
    {
        return redirect('/manual/images/' . $file, 301);
    }

    /** The manual as HTML, with GitHub-style heading IDs for its links */
    public static function renderPlayersManual(string $markdown): string
    {
        // Notes for maintainers stay in the repository
        $markdown = preg_replace('/^> \*\*Keep this manual current\.\*\*.*\R/m', '', $markdown);

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'none',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);

        // The manual's anchors, <a name="slug"></a>, come out escaped like all
        // other HTML: only that exact form becomes an anchor again (#678)
        $html = preg_replace('/&lt;a name=(?:"|&quot;)([A-Za-z0-9_-]+)(?:"|&quot;)&gt;&lt;\/a&gt;/', '<a id="$1"></a>', $html);

        // Images are served by /manual/images; other documents are on GitHub
        $html = preg_replace('/src="images\//', 'src="/manual/images/', $html);
        // Lua blocks in colour (#848)
        $html = \App\Services\LuaHighlight::markdownBlocks($html);
        return preg_replace('/href="(?!https?:|#|\/)([\w.-]+\.md)/', 'href="' . self::REPOSITORY_DOCS . '$1', $html);
    }

    /** An image used by the players' manual */
    public function playersManualImage(string $file)
    {
        $path = base_path(self::MANUAL_IMAGES . '/' . $file);
        abort_unless(is_file($path), 404);
        return response()->file($path, ['Cache-Control' => 'public, max-age=86400']);
    }

    /**
     * Displays query commands table
     * Request $request
     */
    public function queryCommands(Request $request)
    {
        $queryCommands = Query::orderBy('id', 'asc')->get();
        $keyword = $request->get('keyword');
        return view('query-commands', compact('queryCommands', 'keyword'));
    }

    /**
     * One query: how to call it
     */
    public function queryCommand($id)
    {
        $query = Query::findOrFail($id);
        return view('query-command', compact('query'));
    }

    /**
     * Displays the in-game events table
     * @return View
     */
    public function inGameEvents()
    {
        // Nothing observed yet: say so, and where to look instead (#692)
        $empty = !DB::table('observations')->where('drone', 0)->exists();
        return view('in-game-events', compact('empty'));
    }

    /**
     * Used by search engine to retrieve in-game events
     * @param $request Request object
     * @return JsonResponse
     */
    /** An event's effect in plain words (#744: "Increment inventory to 12 units") */
    public static function eventEffect(object $e): string
    {
        $n = fn ($v) => number_format((int) $v);
        $what = $e->resource_name ?? 'goods';
        return match ((int) $e->event_type) {
            1, 2 => 'now holds ' . $n($e->new_quantity) . ' ' . $what . ((int) $e->locked === 1 ? ' in use' : ' aboard'),
            3 => 'moved to ' . ($e->object_name ?? 'a new place'),
            4 => 'sold ' . $what,
            5 => 'bought ' . $what,
            6, 7 => 'credits now ' . $n($e->new_credits),
            8 => 'launched a drone',
            default => (string) ($e->event_type_name ?? ''),
        };
    }

    public function InGameEventsSearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');
        $now = \App\Services\World::gameNow();

        // What the drone has seen (the Central Database, drone 0, by default):
        // its observation rows once the light got there and, worked out here,
        // the events where it is since it arrived (#324 step 20: no longer a
        // row per drone). Only the latest EVENTS_WINDOW of each, by index
        // (#726: with 19 million events the search counted and sorted every
        // row it could see, and never answered)
        $observer = (int) ($request->get('observing_agent') ?? 0);
        $seen = DB::table('observations')->where('drone', $observer)->where('game_t', '<=', $now)
            ->orderByDesc('game_t')->limit(self::EVENTS_WINDOW)->pluck('game_t', 'event')->all();
        $me = $observer > 0 ? DB::table('agents')->where('agent_id', $observer)->first(['object_id', 'arrived_t']) : null;
        if ($me && $me->object_id !== null && Schema::hasColumn('events', 'place')) {
            $seen += DB::table('events')->where('place', $me->object_id)->where('game_t', '>=', (int) $me->arrived_t)
                ->where('game_t', '<=', $now)->orderByDesc('game_t')->limit(self::EVENTS_WINDOW)->pluck('game_t', 'id')->all();
        }
        arsort($seen);
        $seen = array_slice($seen, 0, self::EVENTS_WINDOW, true);

        // The details, from events or, once a month old, events_archive (#694)
        $keyword = $request->get('keyword');
        // A tool locked and unlocked around each action is noise to a reader (#744): left out unless ?all=1
        $all = (bool) $request->get('all');
        $details = function (string $table) use ($seen, $keyword, $all) {
        $query = DB::table($table . ' as events')->select('events.id', 'events.timestamp', 'events.game_t', 'events.event_type', 'acting_agents.name as acting_agent_name', 'acting_agents.company_id as acting_company_id', 'acting_companies.name as acting_company_name',
            'acting_agents.agent_id as acting_agent_id', 'protocols.name as protocol_name', 'affected_companies.name as affected_company_name', 'affected_companies.company_id as affected_company_id',
            'event_types.name as event_type_name', 'affected_agents.agent_id as affected_agent_id', 'affected_agents.name as affected_agent_name', 'resources.id as resource_id',
            'resources.name as resource_name', 'events.locked', 'events.new_quantity', 'events.new_credits', 'events.new_location', 'objects.object_id', 'objects.object_name')
            ->whereIn('events.id', array_keys($seen) ?: [0])
            ->leftJoin('actions', 'events.action', '=', 'actions.id')
            ->leftJoin('agents as acting_agents', 'actions.drone', '=', 'acting_agents.agent_id')
            ->leftJoin('companies as acting_companies', 'acting_agents.company_id', '=', 'acting_companies.company_id')
            ->leftJoin('protocols', 'actions.protocol', '=', 'protocols.id')
            ->leftJoin('event_types', 'events.event_type', '=', 'event_types.id')
            ->leftJoin('agents as affected_agents', 'events.drone', '=', 'affected_agents.agent_id')
            ->leftJoin('companies as affected_companies', 'affected_agents.company_id', '=', 'affected_companies.company_id')
            ->leftJoin('resources', 'events.resource', '=', 'resources.id')
            ->leftJoin('objects', 'events.new_location', '=', 'objects.object_id');
        if (!$all) {
            $query->where(fn ($q) => $q->whereNull('events.locked')->orWhere('events.locked', 0));
        }
        if ($keyword !== null && $keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->where(function ($query) use ($like) {
                foreach (['affected_agents.name', 'acting_agents.name', 'protocols.name', 'affected_companies.name',
                             'resources.name', 'event_types.name', 'objects.object_name'] as $column) {
                    $query->orWhere($column, 'LIKE', $like);
                }
            });
        }
        return $query->get();
        };
        $found = $details('events');
        if (count($found) < count($seen) && Schema::hasTable('events_archive')) {
            $found = $found->concat($details('events_archive'));
        }
        // Newest first by when it happened, the time each row shows (#743:
        // sorted by when it was seen, a 19:54 row sat among 20:24 ones);
        // ?sort=timestamp|asc for oldest first
        $rows = $found->each(fn ($e) => $e->observed_t = $seen[$e->id] ?? null);
        $sort = explode('|', (string) $request->get('sort'));
        $dir = $sort[0] === 'timestamp' && ($sort[1] ?? '') === 'asc' ? 'asc' : 'desc';
        $rows = $rows->sortBy([['game_t', $dir], ['id', $dir]]);
        $rows = $rows->values();

        // Game time, as the rest of the site writes it (#380 round 64: real US time stood out)
        $when = function ($e) {
            $e->when = $e->game_t !== null ? \App\Services\DronePage::when((int) $e->game_t) : null;
            $e->effect = self::eventEffect($e);
            return $e;
        };
        if ($request->get('limit')) {
            $page = $rows->take(10)->map($when)->values();
            return response()->json(['current_page' => 1, 'data' => $page, 'per_page' => 10, 'total' => $page->count(),
                'to' => $page->count(), 'prev_page_url' => null, 'from' => 1, 'window' => self::EVENTS_WINDOW], 200);
        }
        $current = max(1, (int) $request->get('page', 1));
        $page = new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($current, $resultsPerPage)->map($when)->values(),
            $rows->count(), $resultsPerPage, $current, ['path' => $request->url(), 'query' => $request->query()]);
        return response()->json($page->toArray() + ['window' => self::EVENTS_WINDOW], 200);
    }
    /**
     * Displays the About Us information page
     * @return View
     */
    public function aboutUs()
    {
        return view('pages.about-us');
    }

    /**
     * "Keep the lights on" (#522): what the game costs each month, how much
     * is covered, and the Ko-fi button (config/support.php)
     */
    public function support()
    {
        $costs = config('support.costs', []);
        $total = array_sum($costs);
        $covered = (float) config('support.covered', 0);
        return view('pages.support', [
            'costs' => $costs,
            'total' => $total,
            'covered' => $covered,
            'month' => config('support.month', ''),
            'percent' => $total > 0 ? min(100, (int) round(100 * $covered / $total)) : 0,
            'kofi' => trim((string) config('support.kofi', '')),
        ]);
    }

    /**
     * Displays a flash message alone
     * @param $request Request object
     * @return \Illuminate\Http\Response
     */
    public function message(Request $request)
    {
        $this->querystringToFlash($request);
        return view('show_message');
    }


}
