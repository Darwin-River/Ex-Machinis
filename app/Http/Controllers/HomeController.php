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
        // What's happening in the world (#324 step 17)
        $feed = \App\Services\World::feed();
        // The game clock, live (#324 step 20)
        $clock = \App\Services\LaunchQueue::clock();
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
        return response('ok');
    }

    /**
     * Display static page with forth dictionary
     */
    public function forthDictionary()
    {
        return view('pages.forth-dictionary');
    }

    /** Where the players' manual and its images live */
    private const PLAYERS_MANUAL = 'documents/players-manual.md';
    private const MANUAL_IMAGES = 'documents/images';
    private const REPOSITORY_DOCS = 'https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/';

    /**
     * Getting Started: the players' manual (documents/players-manual.md),
     * rendered from the Markdown so the page never drifts from it
     */
    public function playersManual()
    {
        $manual = Cache::rememberForever('players-manual:' . filemtime(base_path(self::PLAYERS_MANUAL)),
            fn () => self::renderPlayersManual(file_get_contents(base_path(self::PLAYERS_MANUAL))));

        return view('pages.players-manual', compact('manual'));
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

        // Images are served by /getting-started/images; other documents are on GitHub
        $html = preg_replace('/src="images\//', 'src="/getting-started/images/', $html);
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
        return view('in-game-events');
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

        $query = DB::table('events')->select('events.timestamp', 'acting_agents.name as acting_agent_name', 'acting_agents.company_id as acting_company_id', 'acting_companies.name as acting_company_name',
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
        // var_dump($spaceObjects);exit;

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
