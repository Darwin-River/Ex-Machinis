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
            $seedling = ['grant' => \App\Services\Seedlings::grant(),
                'mature_at' => \App\Services\Seedlings::grant() + \App\Services\Seedlings::matureMargin(),
                'margin' => \App\Services\Seedlings::matureMargin(), 'shares' => \App\Services\Seedlings::SHARES,
                'credits' => (int) config('forge.starting_credits'), 'hull' => (int) config('forge.seedling_hull_value'),
                'fee' => (int) config('forge.seedling_fee')];
            return view('home-new-rules', ['feed' => \App\Services\World::feedGrouped(8), 'clock' => $clock,
                'status' => \App\Services\LaunchQueue::status(), 'seedling' => $seedling,
                'dash' => \App\Services\Dashboard::compact()]);
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
            \App\Services\PlayerShares::payDividends();
            \App\Services\PlayerBoards::startMonth();
        }
        return response('ok');
    }

    /** Where the players' manual and its images live */
    private const PLAYERS_MANUAL = 'documents/players-manual.md';
    private const MANUAL_IMAGES = 'documents/images';
    private const REPOSITORY_DOCS = 'https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/';

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
        return Cache::rememberForever('players-manual:' . filemtime(base_path(self::PLAYERS_MANUAL)),
            fn () => self::renderPlayersManual(file_get_contents(base_path(self::PLAYERS_MANUAL))));
    }

    /** The IDs of the manual's headings and anchors, for old /getting-started#anchor links */
    public static function manualAnchors(): array
    {
        preg_match_all('/<(?:h[1-6]|a) id="([A-Za-z0-9_-]+)"/', self::manualHtml(), $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Getting started (#719): your first seedling in five minutes, with the
     * numbers in force. Round 70: both home-page buttons led to the whole
     * manual, 80,000 pixels that opened with a changelog from 2020
     */
    public function firstSeedling()
    {
        $grant = \App\Services\Seedlings::grant();
        $seedling = ['grant' => $grant, 'margin' => \App\Services\Seedlings::matureMargin(),
            'mature_at' => $grant + \App\Services\Seedlings::matureMargin(), 'shares' => \App\Services\Seedlings::SHARES,
            'credits' => (int) config('forge.starting_credits'), 'hull' => (int) config('forge.seedling_hull_value'),
            'fee' => (int) config('forge.seedling_fee'), 'push_fee' => (int) config('forge.seedling_push_fee'),
            'cap' => max(intdiv($grant * \App\Services\Seedlings::capPermille(), 1000), $grant + \App\Services\Seedlings::matureMargin(), 1), 'max' => (int) config('forge.seedling_max')];
        $starters = \App\Services\Release::STARTERS;
        unset($starters['trader']);   // a seedling can't trade with other drones (#658)
        $clock = \App\Services\LaunchQueue::clock();
        $anchors = self::manualAnchors();
        return view('pages.first-seedling', compact('seedling', 'starters', 'clock', 'anchors'));
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
    public function InGameEventsSearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');

        // What the drone has seen (the Central Database, drone 0, by default):
        // its observation rows (acting or affected, or once the light got
        // there) and, worked out here, the events where it is since it
        // arrived (#324 step 20: no longer a row per drone)
        $observer = (int) ($request->get('observing_agent') ?? 0);
        $seen = DB::table('observations')->select('event', 'time', 'drone')->where('drone', $observer);
        if ($observer > 0 && Schema::hasColumn('events', 'place')) {
            $seen->union(DB::table('events as le')
                ->join('agents as me', function ($join) use ($observer) {
                    $join->on('le.place', '=', 'me.object_id')->where('me.agent_id', '=', $observer);
                })
                ->whereColumn('le.game_t', '>=', 'me.arrived_t')
                ->where('le.game_t', '<=', \App\Services\World::gameNow())
                ->select('le.id as event', 'le.timestamp as time', DB::raw($observer . ' as drone')));
        }

        $query = DB::table('events')->select('events.timestamp', 'events.game_t', 'events.event_type', 'acting_agents.name as acting_agent_name', 'acting_agents.company_id as acting_company_id', 'acting_companies.name as acting_company_name',
            'acting_agents.agent_id as acting_agent_id', 'acting_agents.name as acting_agent_name', 'protocols.name as protocol_name', 'affected_companies.name as affected_company_name', 'affected_companies.company_id as affected_company_id',
            'event_types.name as event_type_name', 'affected_agents.agent_id as affected_agent_id', 'affected_agents.name as affected_agent_name', 'resources.id as resource_id',
            'resources.name as resource_name', 'events.locked', 'events.new_quantity', 'events.new_credits', 'events.new_location', 'objects.object_id', 'objects.object_name', 'observations.drone as observing_drone')
            ->joinSub($seen, 'observations', 'events.id', '=', 'observations.event') //obligatory to be observed
            ->leftJoin('actions', 'events.action', '=', 'actions.id')
            ->leftJoin('agents as acting_agents', 'actions.drone', '=', 'acting_agents.agent_id')
            ->leftJoin('companies as acting_companies', 'acting_agents.company_id', '=', 'acting_companies.company_id')
            ->leftJoin('protocols', 'actions.protocol', '=', 'protocols.id')
            ->leftJoin('event_types', 'events.event_type', '=', 'event_types.id')
            ->leftJoin('agents as affected_agents', 'events.drone', '=', 'affected_agents.agent_id')
            ->leftJoin('companies as affected_companies', 'affected_agents.company_id', '=', 'affected_companies.company_id')
            ->leftJoin('resources', 'events.resource', '=', 'resources.id')
            ->leftJoin('objects', 'events.new_location', '=', 'objects.object_id');
        //basic restrictions
        $query->where([/*['observations.drone', '=', 0],*/ ['observations.time', '<=', Carbon::now()->toDateTimeString()]]);
        if ($request->get('keyword') !== null)
            $query->where(function ($query) use ($request) {
                $query->where('affected_agents.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('acting_agents.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('protocols.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('affected_companies.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('resources.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('event_types.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('object_name', 'LIKE', "%" . $request->get('keyword') . "%");
            });

        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else
            $query->orderBy('time', 'desc');
        if ($request->get('limit')) {
            // $query->take($request->get('limit'));
            $inGameEvents = $query->take(10)->get();
            $inGameEvents = ['current_page' => 1, 'data' => $inGameEvents, 'per_page' => 10, 'total' => sizeof($inGameEvents), 'to' => sizeof($inGameEvents), 'preve_page_url' => null, 'from' => 1];
        } else
            $inGameEvents = $query->paginate($resultsPerPage);
        // Game time, as the rest of the site writes it (#380 round 64: real US time stood out)
        $when = function ($e) {
            $e->when = $e->game_t !== null ? \App\Services\DronePage::when((int) $e->game_t) : null;
            return $e;
        };
        if (is_array($inGameEvents)) {
            $inGameEvents['data'] = $inGameEvents['data']->map($when);
        } else {
            $inGameEvents->getCollection()->transform($when);
        }

        return response()->json($inGameEvents, 200);
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
