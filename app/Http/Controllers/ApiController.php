<?php

namespace App\Http\Controllers;

use App\Agent;
use App\Services\DronePage;
use App\Services\HouseMarket;
use App\Services\LaunchQueue;
use App\Services\Leaderboard;
use App\Services\Shares;
use App\Services\LiveQuery;
use App\Services\Recipes;
use App\Services\Release;
use App\Services\World;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The JSON API (#380): what the website shows, for programs, so players and
 * AI agents can release and follow their companies without reading
 * pages. Everything in it is already public on the website; like the website
 * it needs no account, and it shows no House information (no price list:
 * station drones' orders are in the order book like anyone's). Documented in
 * documents/api.md.
 */
class ApiController extends Controller
{
    /** The game clock and whether the world has room for a release */
    public function clock(): JsonResponse
    {
        $now = World::gameNow();
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $clock = LaunchQueue::clock();
        $scale = $clock['scale'] ?? null;
        // The game engine records its pace once a game minute or so
        // (load_checked_at, real time): a stale record means it has stopped,
        // and drones are waiting (#380 round 35: a stall looked like a slow drone)
        $load = DB::table('game_clock')->where('id', 1)->first(['load_checked_at', 'slices_per_minute', 'lag_seconds']);
        $seen = ($load && $load->load_checked_at) ? max(0, time() - (int) $load->load_checked_at) : null;
        return response()->json([
            'game_time' => $now,
            'game_day' => round(($now - $epoch) / 86400, 3),
            'when' => DronePage::when($now),
            'scale' => $scale,
            'real_seconds_per_game_day' => $scale ? (int) round(86400 / $scale) : null,
            // The pace it runs at when the engines keep up, and whether it's
            // slower now (#418: players took the pace now for the usual one)
            'target_scale' => $clock['target'] ?? $scale,
            'target_real_seconds_per_game_day' => ($clock['target'] ?? $scale) ? (int) round(86400 / ($clock['target'] ?? $scale)) : null,
            'floor_scale' => $clock['floor'] ?? null,
            'slowed' => $scale !== null && isset($clock['target']) && $scale < $clock['target'] * 0.999,
            'world_full' => $clock['full'] ?? false,
            'releases_waiting' => $clock['waiting'] ?? 0,
            'engine' => [
                'running' => $seen !== null && $seen < 180,
                'last_seen_seconds_ago' => $seen,
                'turns_per_minute' => $load && $load->slices_per_minute !== null ? (int) $load->slices_per_minute : null,
                'behind_seconds' => $load && $load->lag_seconds !== null ? (float) $load->lag_seconds : null,
            ],
        ]);
    }

    /** A company by ID, or by name with ?name= */
    public function company(Request $request, ?int $id = null): JsonResponse
    {
        $company = $id !== null
            ? DB::table('companies')->where('company_id', $id)->first()
            : DB::table('companies')->where('name', (string) $request->query('name'))->first();
        if (!$company) {
            return $this->notFound('There is no such company.');
        }
        $drones = Agent::where('company_id', $company->company_id)->orderBy('agent_id')->get();
        $board = Leaderboard::wealth();
        $wealth = $board[$company->company_id]['wealth'] ?? null;
        return response()->json([
            'id' => (int) $company->company_id,
            'name' => $company->name,
            'credits' => (int) $company->credits,
            'net_wealth' => $wealth,
            // Game days since it was founded, and its place among every company
            // at its age: its wealth now against theirs when they were as old (#548, #614)
            'age_days' => Leaderboard::age($company->founded_t, World::gameNow()),
            'peer_rank' => Leaderboard::peerRank((int) $company->company_id, $board),
            'ended' => $company->eliminated_at !== null,
            'drones' => $drones->map(fn (Agent $a) => $this->droneSummary($a))->all(),
            // The stock market (#471): its share price, holders, orders, trades
            // and the shares it holds in others
            'shares' => Shares::summary((int) $company->company_id),
            // Code pushes (#553): whether it takes them, what the next costs, and each so far
            'pushes' => \App\Services\CodePush::summary($company),
            // What its drones' thinking costs it (#544)
            'compute' => \App\Services\ProcessorTax::of((int) $company->company_id),
            // Whether it can keep its drones flying (#537)
            'health' => \App\Services\CompanyHealth::of((int) $company->company_id)
                // and each working drone: idle hours, last status, hold (#540)
                + ['drones' => \App\Services\CompanyHealth::drones((int) $company->company_id)],
        ]);
    }

    /**
     * Pushes a new script to a company's working drones (#553), with the
     * password it was released with. Each drone gets it at the speed of
     * light from Earth and restarts on it with fresh variables
     */
    public function push(Request $request, int $id): JsonResponse
    {
        if (($bad = $this->scriptRules($request, false)) !== null) {
            return $bad;
        }
        $result = \App\Services\CodePush::push($id, (string) $request->input('password', ''), $this->script($request));
        if (!$result['ok']) {
            return response()->json(['error' => $result['error']], $result['status']);
        }
        return response()->json(['push' => $result['number'], 'fee' => $result['fee'],
            'fee_percent' => $result['permille'] / 10, 'next_fee_percent' => $result['next_permille'] / 10,
            'drones' => $result['drones'],
            'message' => 'Pushed to ' . count($result['drones']) . ' drone(s): each gets it at the speed of light from '
                . 'Earth, then restarts its launched function on the new script with fresh variables.',
            'warning' => Release::redefinedWarning($this->script($request))], 202);
    }

    /** A drone: where it is, what it carries, what it's doing and has said */
    public function drone(int $id): JsonResponse
    {
        $agent = Agent::where('agent_id', $id)->first();
        if (!$agent) {
            return $this->notFound('There is no such drone.');
        }
        $state = DronePage::state($agent);
        $activity = DronePage::activity($agent);
        $postMortem = DronePage::postMortem($agent);
        $code = DronePage::code($agent);
        return response()->json($this->droneSummary($agent) + [
            'company' => ['id' => (int) $agent->company_id, 'name' => $state['company']->name ?? null],
            'parent' => $agent->parent_id ? (int) $agent->parent_id : null,
            'clones' => $state['clones']->pluck('agent_id')->map(fn ($c) => (int) $c)->all(),
            'generation' => $state['generation'],
            'instructions' => $state['instructions'],
            'fuel_last_day' => $state['fuel_day'],
            'crashes' => $state['crashes'],
            'cargo' => DB::table('sqInventory as i')->join('resources as r', 'r.id', '=', 'i.resource')
                ->where('i.drone', $id)->where('i.units', '>', 0)->orderBy('i.resource')
                ->get(['i.resource', 'r.name', 'i.units'])
                ->map(fn ($c) => ['resource' => (int) $c->resource, 'name' => $c->name, 'units' => (int) $c->units])->all(),
            'now' => $activity['now'] ?? null,
            'timeline' => $activity['timeline'] ?? ($postMortem['timeline'] ?? []),
            'trades' => DronePage::deals($id),
            'messages' => DronePage::messages($agent, 50)->map(fn ($m) => [
                'when' => DronePage::when((int) $m->game_t), 'game_time' => (int) $m->game_t,
                'subject' => $m->subject, 'body' => $m->body])->all(),
            'post_mortem' => $postMortem ? ['cause' => $postMortem['cause'] ?? null] : null,
            'script' => $code ? $code['release']->source : null,
        ]);
    }

    /**
     * A drone's actions, newest first, a page at a time (#494): ?limit= (up
     * to 100, 50 if left out) and ?before= (an action ID: the ones before
     * it). next_before gives the next page, null at the start
     */
    public function droneTimeline(Request $request, int $id): JsonResponse
    {
        if (!Agent::where('agent_id', $id)->exists()) {
            return $this->notFound('There is no such drone.');
        }
        $limit = max(1, min(100, (int) $request->query('limit', 50)));
        $before = $request->query('before') !== null ? (int) $request->query('before') : null;
        $timeline = DronePage::timeline($id, $limit, $before);
        return response()->json([
            'drone' => $id,
            'timeline' => $timeline,
            'next_before' => count($timeline) >= $limit ? end($timeline)['action'] : null,
        ]);
    }

    /**
     * Open orders, every one (the Market page shows only the best): sell
     * (?side=sell) or buy (the default), for one resource (?resource=) and
     * one place (?place=, an object ID or name)
     */
    /**
     * A drone's stock a pull or push can move (#502), for an order of the
     * type (4 sell, 5 buy) on latest_orders AS o: its free holding, less its
     * pending depletions (a seller) or plus its pending accumulations (a
     * buyer). The same as the order-book queries' (build_game_data.py movable).
     * An aborted action's events stay pending for ever and never happen: not
     * counted (#612)
     */
    public static function movable(int $type): string
    {
        [$sign, $pending] = $type === 4 ? ['-', 2] : ['+', 1];
        return "(IFNULL((SELECT h.units FROM holdings AS h WHERE h.drone = o.drone AND h.resource = o.resource
                    AND h.installed = 0 AND h.locked = 0), 0) $sign
                 IFNULL((SELECT ABS(SUM(e.new_quantity)) FROM events AS e FORCE INDEX (events_inventory)
                    WHERE e.drone = o.drone AND e.resource = o.resource AND e.installed = 0 AND e.locked = 0
                    AND e.outcome = 0 AND e.event_type = $pending
                    AND NOT EXISTS (SELECT 1 FROM actions AS x WHERE x.id = e.action AND x.aborted = 1)), 0))";
    }

    public function orders(Request $request): JsonResponse
    {
        $db = DB::connection();
        $type = $request->query('side') === 'sell' ? 4 : 5;
        $where = '';
        $bind = [$type];
        if ($request->filled('resource')) {
            $where .= ' AND o.resource = ?';
            $bind[] = (int) $request->query('resource');
        }
        if ($request->filled('place')) {
            $place = (string) $request->query('place');
            $where .= ctype_digit($place) ? ' AND a.object_id = ?' : ' AND p.object_name = ?';
            $bind[] = ctype_digit($place) ? (int) $place : $place;
        }
        // ?company=: one company's open orders (#380 round 36), by number or name
        if ($request->filled('company')) {
            $company = (string) $request->query('company');
            $where .= ctype_digit($company) ? ' AND a.company_id = ?' : ' AND c.name = ?';
            $bind[] = ctype_digit($company) ? (int) $company : $company;
        }
        // The units a pull or push can really move, as the drones' order books
        // count them (#502): free stock (not installed, not locked), less what
        // is on its way out of a seller, or plus what is on its way into a buyer
        $movable = self::movable($type);
        $rows = $db->select(
            "SELECT * FROM (SELECT o.drone, a.name AS drone_name, o.resource, r.name AS resource_name, o.price,
                    o.quantity, $movable AS movable, a.object_id AS place_id, p.object_name AS place,
                    a.company_id, c.name AS company_name
             FROM latest_orders o
             JOIN agents a ON a.agent_id = o.drone AND a.wrecked_at IS NULL
             LEFT JOIN companies c ON c.company_id = a.company_id
             LEFT JOIN resources r ON r.id = o.resource
             LEFT JOIN objects p ON p.object_id = a.object_id
             WHERE o.event_type = ? AND o.is_open = 1 $where) b
             WHERE " . ($type === 4 ? 'movable > quantity' : 'movable < quantity') . "
             ORDER BY resource, place, price " . ($type === 4 ? 'ASC' : 'DESC') . ' LIMIT 500',
            $bind);
        foreach ($rows as $r) {
            $r->units = abs((int) $r->movable - (int) $r->quantity);
        }
        return response()->json(array_map(fn ($r) => [
            'drone' => (int) $r->drone, 'drone_name' => $r->drone_name, 'resource' => (int) $r->resource,
            'resource_name' => $r->resource_name, 'price' => (int) $r->price, 'units' => (int) $r->units,
            'place_id' => (int) $r->place_id, 'place' => $r->place,
            'company_id' => (int) $r->company_id, 'company' => $r->company_name,
        ], $rows));
    }

    /** The latest trades between drones */
    public function trades(Request $request): JsonResponse
    {
        return response()->json(World::trades(min(200, max(1, (int) $request->query('limit', 50)))));
    }

    /** Market history (#380): trades per game day, resource and place */
    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), ['days' => 'integer|min:1|max:365', 'resource' => 'integer|min:1', 'place' => 'integer|min:1']);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }
        $resource = $request->query('resource');
        $place = $request->query('place');
        return response()->json(World::history((int) $request->query('days', 60),
            $resource === null ? null : (int) $resource, $place === null ? null : (int) $place));
    }

    /** Net wealth, richest first */
    public function leaderboard(Request $request): JsonResponse
    {
        $limit = min(500, max(1, (int) $request->query('limit', 50)));
        // Every company at the same age (#614): its net wealth when it was that old
        if (is_numeric($request->query('age'))) {
            $board = Leaderboard::atAge(min(100000, max(0, round((float) $request->query('age'), 1))), $limit);
            return response()->json(['age' => $board['age'], 'of' => $board['of'], 'too_young' => $board['too_young'],
                'ended_before' => $board['ended_before'], 'companies' => array_map(fn ($r) => [
                    'company_id' => $r['company_id'], 'company' => $r['company'], 'net_wealth_at_age' => $r['wealth'],
                    'net_wealth' => $r['now'], 'ended' => $r['ended'], 'age_days' => $r['age'], 'gap_days' => $r['gap'],
                ], $board['rows'])]);
        }
        return response()->json(array_map(fn ($r) => [
            'company_id' => $r['company_id'], 'company' => $r['company'], 'drones' => $r['drones'],
            'net_wealth' => $r['wealth'], 'released_drone' => $r['drone'], 'age_days' => $r['age'],
        ], Leaderboard::allTime($limit,
            is_numeric($request->query('max_age')) ? max(0, (float) $request->query('max_age')) : null)));
    }

    /** Where drones are, busiest first */
    public function places(): JsonResponse
    {
        return response()->json(array_map(fn ($p) => [
            'id' => (int) $p['object_id'], 'name' => $p['object_name'],
            'working' => (int) $p['working'], 'wrecks' => (int) $p['wrecks'],
        ], World::places()));
    }

    /** The resources (IDs for the game words), protocols and queries */
    public function resources(): JsonResponse
    {
        return response()->json(DB::table('resources')->orderBy('id')->get(['id', 'name', 'mass'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'mass' => (int) $r->mass])->all());
    }

    public function protocols(): JsonResponse
    {
        return response()->json(DB::table('protocols')->orderBy('id')->get(['id', 'name', 'description'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'description' => $p->description])->all());
    }

    /** Recipes (#380 round 36): all, or ?resource= those that make it */
    public function recipes(Request $request): JsonResponse
    {
        $all = Recipes::all();
        $list = $request->filled('resource') ? Recipes::making((int) $request->query('resource'), $all) : array_values($all);
        return response()->json($list);
    }

    /** What one unit takes, all the way down to refined goods (?down_to=1: ores) */
    public function recipeTree(Request $request, int $resource): JsonResponse
    {
        if (!DB::table('resources')->where('id', $resource)->exists()) {
            return $this->notFound('There is no such resource.');
        }
        $downTo = (int) $request->query('down_to', 2) === 1 ? 1 : 2;
        return response()->json(Recipes::tree($resource, $downTo));
    }

    /** The House's booms, slumps, shortages and gluts under way (#380 round 36) */
    public function news(): JsonResponse
    {
        return response()->json(HouseMarket::news());
    }

    /**
     * The House's demand (#550): for each exchange and good it buys, its bid
     * now, the units it still takes today, its day's demand, its listed price
     * and the game hours until it refills. ?resource= and ?place= narrow it;
     * ?company= counts that company's sales into it today (`mine`, #608)
     */
    public function houseDemand(Request $request): JsonResponse
    {
        $int = fn ($k) => is_numeric($request->query($k)) ? (int) $request->query($k) : null;
        return response()->json(HouseMarket::demand($int('resource'), $int('place'), $int('company')));
    }

    /**
     * The stock exchange (#551): every listed company (price, value, sales,
     * payback, health), shares for sale, buy orders, open offerings and the
     * latest trades, as the Stock exchange page shows them
     */
    public function shares(Request $request): JsonResponse
    {
        return response()->json(Shares::exchange(min(200, max(1, (int) $request->query('trades', 25)))));
    }

    /** A company's share price by game day (#551): low, high, last and shares traded */
    public function shareHistory(int $id): JsonResponse
    {
        if (!DB::table('companies')->where('company_id', $id)->exists()) {
            return $this->notFound('There is no such company.');
        }
        return response()->json(Shares::history($id));
    }

    /** A company's sales, purchases and net by game day (#600) */
    /**
     * Its code's lineage (#616): each revision's parents (the earlier code it
     * carries, with shares) and new code, and the other companies' revisions
     * that carry its code
     */
    public function companyLineage(int $id): JsonResponse
    {
        if (!DB::table('companies')->where('company_id', $id)->exists()) {
            return $this->notFound('There is no such company.');
        }
        return response()->json(\App\Services\CodeLineage::ofCompany($id) ?? ['revisions' => [], 'children' => []]);
    }

    public function companyEarnings(Request $request, int $id): JsonResponse
    {
        if (!DB::table('companies')->where('company_id', $id)->exists()) {
            return $this->notFound('There is no such company.');
        }
        $days = is_numeric($request->query('days')) ? (int) $request->query('days') : 30;
        return response()->json(\App\Services\CompanyEarnings::daily($id, $days));
    }

    /** A company's credits and net wealth once a game day (#380 round 36) */
    public function companyHistory(int $id): JsonResponse
    {
        if (!DB::table('companies')->where('company_id', $id)->exists()) {
            return $this->notFound('There is no such company.');
        }
        return response()->json(Leaderboard::history($id));
    }

    public function queries(): JsonResponse
    {
        return response()->json(LiveQuery::catalog());
    }

    /** The warnings the Release page would give, and whether it would take the script */
    public function check(Request $request): JsonResponse
    {
        // Any length: the check says how long it is against the limit (#557)
        $validator = Validator::make($request->all(), ['script' => 'required|string|max:200000',
            'company' => 'nullable|integer']);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray()], 422);
        }
        $script = $this->script($request);
        $length = mb_strlen($script);
        $max = (int) config('forge.max_script_length');
        $problem = $length > $max
            ? 'Your script is ' . number_format($length) . ' characters long: a release or push takes at most '
                . number_format($max) . '. Cut comments, blank lines or functions it no longer calls.'
            : Release::scriptProblem($script);
        $warning = Release::redefinedWarning($script);
        if ($length <= $max && $length > $max * 0.9) {
            $warning = trim(($warning ?? '') . ' Your script is ' . number_format($length) . ' characters long, close to the '
                . number_format($max) . ' a release or push takes.');
        }
        $out = [
            'ok' => $problem === null,
            'error' => $problem,
            'warning' => $warning,
            'language' => Release::isLua($script) ? 'lua' : 'forth',   // #380
            'length' => $length,
            'max_length' => $max,
        ];
        // A push preview (#558): what pushing this to the company would cost now
        if ($request->filled('company')) {
            $out['push'] = \App\Services\CodePush::preview((int) $request->input('company'));
        }
        return response()->json($out);
    }

    /** Releases a script as a new company, as the Release page does */
    public function release(Request $request): JsonResponse
    {
        if (($bad = $this->scriptRules($request, true)) !== null) {
            return $bad;
        }
        $script = $this->script($request);
        if ($problem = Release::scriptProblem($script)) {
            return response()->json(['error' => $problem], 422);
        }
        $name = (string) $request->input('company_name');
        $kit = $request->input('kit');
        // A password lets its owner push new code later (#553): kept as a hash
        $hash = \App\Services\CodePush::hash($request->filled('password') ? (string) $request->input('password') : null);
        if (LaunchQueue::full()) {
            $place = LaunchQueue::enqueue(Release::owner(), $name, $script, null, null, $kit, $hash);
            return response()->json(['queued' => true, 'place_in_line' => $place, 'company_name' => $name,
                'message' => 'The world is full: the company launches as soon as there is room.'], 202);
        }
        $agent = Release::release(Release::owner(), $name, $script, null, null, null, $kit);
        \App\Services\CodePush::setHash((int) $agent->company_id, $hash);
        return response()->json([
            'push_password' => $hash !== null,
            'queued' => false,
            'company' => ['id' => (int) $agent->company_id, 'name' => $name],
            'drone' => ['id' => (int) $agent->agent_id, 'name' => $agent->name],
            'page' => url('spacecraft/' . $agent->agent_id),
            'warning' => Release::redefinedWarning($script),
        ], 201);
    }

    private function droneSummary(Agent $a): array
    {
        return [
            'id' => (int) $a->agent_id,
            'name' => $a->name,
            'place_id' => $a->object_id ? (int) $a->object_id : null,
            'place' => $a->object_id ? DB::table('objects')->where('object_id', $a->object_id)->value('object_name') : null,
            'status' => DronePage::status($a),
            // What its script says it is doing (set_status, #380)
            'script_status' => $a->script_status !== null ? ['text' => $a->script_status,
                'set' => DronePage::when((int) $a->script_status_t), 'set_game_time' => (int) $a->script_status_t] : null,
            // While it flies, place is where it left from; this says where it is going
            'flying_to' => ($f = DronePage::flight($a)) ? ['place_id' => $f['to_id'], 'place' => $f['to'],
                'arrives' => $f['arrives'], 'arrives_game_time' => $f['arrives_game_time']] : null,
            'fuel' => (int) (DB::table('sqInventory')->where('drone', $a->agent_id)->where('resource', DronePage::FUEL)->max('units') ?? 0),
            'wrecked' => $a->wrecked_at !== null,
            'wreck_cause' => $a->wreck_cause,
        ];
    }

    /** The request's script, if it breaks the form's rules a JSON 422 */
    private function scriptRules(Request $request, bool $needsCompanyName): ?JsonResponse
    {
        $rules = ['script' => 'required|string|max:' . config('forge.max_script_length'),
                  'kit' => 'nullable|in:' . implode(',', array_keys(Release::KITS)),
                  'password' => 'nullable|string|min:' . config('forge.push_password_min') . '|max:200'];
        if ($needsCompanyName) {
            $rules['company_name'] = ['required', 'string', 'min:3', 'max:45', 'unique:companies,name',
                function ($attribute, $value, $fail) {
                    if (LaunchQueue::nameWaiting((string) $value)) {
                        $fail('A company of that name is waiting in the launch queue.');
                    }
                }];
        }
        $validator = Validator::make($request->all(), $rules);
        return $validator->fails() ? response()->json(['error' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray()], 422) : null;
    }

    private function script(Request $request): string
    {
        return str_replace("\r\n", "\n", (string) $request->input('script'));
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 404);
    }
}
