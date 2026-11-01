<?php

namespace App\Http\Controllers;

use App\Agent;
use App\Services\DronePage;
use App\Services\LaunchQueue;
use App\Services\Leaderboard;
use App\Services\LiveQuery;
use App\Services\Release;
use App\Services\TestWorld;
use App\Services\World;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The JSON API (#380): what the website shows, for programs, so players and
 * AI agents can release, test and follow their companies without reading
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
        return response()->json([
            'game_time' => $now,
            'game_day' => round(($now - $epoch) / 86400, 3),
            'when' => DronePage::when($now),
            'scale' => $clock['scale'] ?? null,
            'world_full' => $clock['full'] ?? false,
            'releases_waiting' => $clock['waiting'] ?? 0,
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
            'crashes' => $state['crashes'],
            'cargo' => DB::table('sqInventory as i')->join('resources as r', 'r.id', '=', 'i.resource')
                ->where('i.drone', $id)->where('i.units', '>', 0)->orderBy('i.resource')
                ->get(['i.resource', 'r.name', 'i.units'])
                ->map(fn ($c) => ['resource' => (int) $c->resource, 'name' => $c->name, 'units' => (int) $c->units])->all(),
            'now' => $activity['now'] ?? null,
            'timeline' => $activity['timeline'] ?? ($postMortem['timeline'] ?? []),
            'trades' => $activity['deals'] ?? [],
            'messages' => DronePage::messages($agent, 50)->map(fn ($m) => [
                'when' => DronePage::when((int) $m->game_t), 'game_time' => (int) $m->game_t,
                'subject' => $m->subject, 'body' => $m->body])->all(),
            'post_mortem' => $postMortem ? ['cause' => $postMortem['cause'] ?? null] : null,
            'script' => $code ? $code['release']->source : null,
        ]);
    }

    /**
     * Open orders, every one (the Market page shows only the best): sell
     * (?side=sell) or buy (the default), for one resource (?resource=) and
     * one place (?place=, an object ID or name)
     */
    public function orders(Request $request): JsonResponse
    {
        $type = $request->query('side') === 'sell' ? 4 : 5;
        $open = $type === 4 ? 'IFNULL(i.units, 0) - e.new_quantity > 0' : 'e.new_quantity - IFNULL(i.units, 0) > 0';
        $where = '';
        $bind = [$type];
        if ($request->filled('resource')) {
            $where .= ' AND e.resource = ?';
            $bind[] = (int) $request->query('resource');
        }
        if ($request->filled('place')) {
            $place = (string) $request->query('place');
            $where .= ctype_digit($place) ? ' AND a.object_id = ?' : ' AND o.object_name = ?';
            $bind[] = ctype_digit($place) ? (int) $place : $place;
        }
        $rows = DB::select(
            "SELECT e.drone, a.name AS drone_name, e.resource, r.name AS resource_name, e.new_credits AS price,
                    ABS(IFNULL(i.units, 0) - e.new_quantity) AS units, a.object_id AS place_id, o.object_name AS place
             FROM events e
             JOIN (SELECT MAX(id) AS id FROM events WHERE event_type = ? AND outcome = 1 GROUP BY drone, resource) l ON l.id = e.id
             JOIN agents a ON a.agent_id = e.drone AND a.wrecked_at IS NULL
             LEFT JOIN resources r ON r.id = e.resource
             LEFT JOIN objects o ON o.object_id = a.object_id
             LEFT JOIN sqInventory i ON i.drone = e.drone AND i.resource = e.resource
             WHERE $open $where
             ORDER BY e.resource, o.object_name, e.new_credits " . ($type === 4 ? 'ASC' : 'DESC') . ' LIMIT 500',
            $bind);
        return response()->json(array_map(fn ($r) => [
            'drone' => (int) $r->drone, 'drone_name' => $r->drone_name, 'resource' => (int) $r->resource,
            'resource_name' => $r->resource_name, 'price' => (int) $r->price, 'units' => (int) $r->units,
            'place_id' => (int) $r->place_id, 'place' => $r->place,
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

    /** Starts a test run in the test world, as the Test button does */
    public function test(Request $request): JsonResponse
    {
        if (!TestWorld::available()) {
            return response()->json(['error' => 'The test world is not running right now.'], 503);
        }
        if (($bad = $this->scriptRules($request, false)) !== null) {
            return $bad;
        }
        $script = $this->script($request);
        if ($problem = Release::scriptProblem($script)) {
            return response()->json(['error' => $problem], 422);
        }
        $token = TestWorld::run($script, $request->input('kit'));
        return response()->json(['token' => $token, 'results' => url('api/v1/test/' . $token),
            'page' => url('release/test/' . $token), 'warning' => Release::redefinedWarning($script)], 201);
    }

    /** A test run's results so far */
    public function testRun(string $token): JsonResponse
    {
        $run = TestWorld::available() ? TestWorld::runOf($token) : null;
        if (!$run) {
            return $this->notFound('There is no such test run (it may have been cleared to make room).');
        }
        return response()->json([
            'drone' => (int) $run->drone->agent_id,
            'credits' => (int) $run->credits,
            'wrecked' => $run->drone->wrecked_at !== null,
            'crashes' => (int) $run->crashes,
            'messages' => $run->messages->map(fn ($m) => ['subject' => $m->subject, 'body' => $m->body])->all(),
            'events' => $run->events->map(fn ($e) => ['type' => $e->type_name, 'resource' => $e->resource_name,
                'quantity' => $e->new_quantity, 'credits' => $e->new_credits, 'outcome' => $e->outcome])->all(),
            'trades' => $run->trades->map(fn ($t) => ['resource' => (int) $t->resource, 'units' => (int) $t->units,
                'credits' => (int) $t->credits, 'seller' => (int) $t->seller, 'buyer' => (int) $t->buyer])->all(),
        ]);
    }

    private function droneSummary(Agent $a): array
    {
        return [
            'id' => (int) $a->agent_id,
            'name' => $a->name,
            'place_id' => $a->object_id ? (int) $a->object_id : null,
            'place' => $a->object_id ? DB::table('objects')->where('object_id', $a->object_id)->value('object_name') : null,
            'status' => DronePage::status($a),
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
