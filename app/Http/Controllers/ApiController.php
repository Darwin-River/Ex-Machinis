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
        $wealth = Leaderboard::wealth()[$company->company_id]['wealth'] ?? null;
        return response()->json([
            'id' => (int) $company->company_id,
            'name' => $company->name,
            'credits' => (int) $company->credits,
            'net_wealth' => $wealth,
            'ended' => $company->eliminated_at !== null,
            'drones' => $drones->map(fn (Agent $a) => $this->droneSummary($a))->all(),
            // The stock market (#471): its share price, holders, orders, trades
            // and the shares it holds in others
            'shares' => Shares::summary((int) $company->company_id),
            // What its drones' thinking costs it (#544)
            'compute' => \App\Services\ProcessorTax::of((int) $company->company_id),
            // Whether it can keep its drones flying (#537)
            'health' => \App\Services\CompanyHealth::of((int) $company->company_id)
                // and each working drone: idle hours, last status, hold (#540)
                + ['drones' => \App\Services\CompanyHealth::drones((int) $company->company_id)],
        ]);
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
     * buyer). The same as the order-book queries' (build_game_data.py movable)
     */
    public static function movable(int $type): string
    {
        [$sign, $pending] = $type === 4 ? ['-', 2] : ['+', 1];
        return "(IFNULL((SELECT h.units FROM holdings AS h WHERE h.drone = o.drone AND h.resource = o.resource
                    AND h.installed = 0 AND h.locked = 0), 0) $sign
                 IFNULL((SELECT ABS(SUM(e.new_quantity)) FROM events AS e FORCE INDEX (events_inventory)
                    WHERE e.drone = o.drone AND e.resource = o.resource AND e.installed = 0 AND e.locked = 0
                    AND e.outcome = 0 AND e.event_type = $pending), 0))";
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
        return response()->json(array_map(fn ($r) => [
            'company_id' => $r['company_id'], 'company' => $r['company'], 'drones' => $r['drones'],
            'net_wealth' => $r['wealth'], 'released_drone' => $r['drone'],
        ], Leaderboard::allTime(min(500, max(1, (int) $request->query('limit', 50))))));
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
        if (($bad = $this->scriptRules($request, false)) !== null) {
            return $bad;
        }
        $script = $this->script($request);
        return response()->json([
            'ok' => Release::scriptProblem($script) === null,
            'error' => Release::scriptProblem($script),
            'warning' => Release::redefinedWarning($script),
            'language' => Release::isLua($script) ? 'lua' : 'forth',   // #380
        ]);
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
        if (LaunchQueue::full()) {
            $place = LaunchQueue::enqueue(Release::owner(), $name, $script, null, null, $kit);
            return response()->json(['queued' => true, 'place_in_line' => $place, 'company_name' => $name,
                'message' => 'The world is full: the company launches as soon as there is room.'], 202);
        }
        $agent = Release::release(Release::owner(), $name, $script, null, null, null, $kit);
        return response()->json([
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
                  'kit' => 'nullable|in:' . implode(',', array_keys(Release::KITS))];
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
