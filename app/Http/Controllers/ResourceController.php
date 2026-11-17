<?php

namespace App\Http\Controllers;

use App\Event;
use App\EventType;
use App\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;

class ResourceController extends Controller
{
    /**
     * Displays list of resources
     */
    public function index()
    {
        // Each with its thumbnail, when it has one (#707): html/images/resources/<id>-thumb.webp,
        // made by tools/render with its resource page's turntable
        $resources = Resource::orderBy('id', 'asc')->get()->each(function ($resource) {
            $thumb = 'images/resources/' . $resource->id . '-thumb.webp';
            $resource->setAttribute('thumb', is_file(public_path($thumb)) ? url($thumb) : null);
        });
        return view('resources.index', compact('resources'));
    }

    /**
     * Displays resource
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function view($id)
    {
        $resource = Resource::findOrFail($id);
        // Its recent price and volume (#597): the Market history chart, for this resource
        $traded = DB::table('trades')->where('resource', $resource->id)->exists();
        ['places' => $places, 'today' => $today] = \App\Services\MarketHistory::frame();
        // Its whole order book and what the House buys and sells of it (#721:
        // moved here from the Market page, which shows one row a good)
        $book = \App\Services\MarketBoard::book((int) $resource->id);
        $board = \App\Services\MarketBoard::row((int) $resource->id);
        $demand = \App\Services\HouseMarket::demand((int) $resource->id);
        $supply = \App\Services\HouseMarket::supply((int) $resource->id);

        $parts = self::parts((int) $resource->id);

        return view('resources.view', compact('resource', 'traded', 'places', 'today', 'book', 'board', 'demand', 'supply', 'parts'));
    }

    /**
     * A craft's numbered picture (#802), rendered by tools/render (render.py
     * resource --parts): html/images/resources/<id>-parts.webp, and beside it
     * <id>-parts.json, the subsystems its recipe uses and where each shows in
     * the picture. Null for a resource without one.
     *
     * @return array{image: string, width: int, height: int, items: array}|null
     */
    public static function parts(int $id): ?array
    {
        $base = "images/resources/$id-parts";
        if (!is_file(public_path("$base.webp")) || !is_file(public_path("$base.json"))) {
            return null;
        }
        $data = json_decode((string) file_get_contents(public_path("$base.json")), true);
        if (!is_array($data) || empty($data['recipe'])) {
            return null;
        }
        $names = Resource::whereIn('id', array_column($data['recipe'], 'id'))->pluck('name', 'id');
        $items = [];
        foreach ($data['recipe'] as $k => $r) {
            $items[] = [
                'n' => $k + 1,
                'id' => (int) $r['id'],
                'name' => $names[$r['id']] ?? "Resource {$r['id']}",
                'qty' => (int) $r['qty'],
                // Where it shows in the picture, as percentages; none if it is out of sight
                'at' => array_values(array_map(fn ($p) => [round($p['x'] * 100, 2), round($p['y'] * 100, 2)],
                    array_filter($data['parts'] ?? [], fn ($p) => (int) $p['id'] === (int) $r['id']))),
            ];
        }

        return ['image' => url("$base.webp"), 'width' => (int) $data['width'], 'height' => (int) $data['height'], 'items' => $items];
    }

    /**
     * Displays the list of resource buy orders
     */
    public function buyOrders()
    {
        return view('resources.buy-orders');
    }

    /**
     * Used by Vue to retrieve buy orders with filters
     * @param $request Request object
     * @return JsonResponse
     */
    public function buyOrdersSearch(Request $request)
    {
        if ($request->get('resource'))
            $resultsPerPage = 10;
        else
            $resultsPerPage = Config::get('constants.options.results_per_page');
        $query = DB::table('events')->select('agents.name as agent_name', 'agents.agent_id', 'resources.name as resource_name', 'resources.id as resource_id', DB::raw('(events.timestamp) as timestamp'),
            DB::raw('events.new_credits as price'), 'objects.object_name', 'objects.object_id', DB::raw('(events.new_quantity-IFNULL(sqInventory.units,0)) AS volume'), 'events.new_quantity as new_quantity', 'sqInventory.units as inventory_units')
            ->leftJoin('agents', 'agents.agent_id', '=', 'events.drone')
            ->leftJoin('resources', 'events.resource', '=', 'resources.id')
            ->leftJoin('objects', 'objects.object_id', '=', 'agents.object_id')
            ->leftJoin('sqInventory', function ($join) {
                $join->on('sqInventory.drone', '=', 'events.drone');
                $join->on('sqInventory.resource', '=', 'events.resource');
            })
            ->join(DB::raw('(SELECT MAX(events.id) as id FROM events LEFT JOIN sqInventory ON sqInventory.drone = events.drone AND sqInventory.resource =  events.resource
             WHERE events.event_type = ' . EventType::BUY_RESOURCE . ' AND new_quantity IS NOT NULL AND ((events.new_quantity-IFNULL(sqInventory.units,0)) > 0)
               GROUP BY events.drone, events.resource ORDER BY id DESC) AS events_latest '), 'events_latest.id', '=', 'events.id');

        if ($request->get('resource')) {
            $query->where('events.resource', $request->get('resource'));
        }
        if ($request->get('location'))
            $query->where('objects.object_name', $request->get('location'));
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else {
            if ($request->get('resource'))
                $query->orderBy('price', 'desc');
            else
                $query->orderBy('timestamp', 'desc');
        }
        /*  $query->groupBy('events.drone', 'events.resource');*/
        $orders = $query->paginate($resultsPerPage);

        return response()->json($orders, 200);
    }

    /**
     * Displays the list of resource sell orders
     */
    public function sellOrders()
    {
        return view('resources.sell-orders');
    }

    /**
     * Used by Vue to retrieve buy orders with filters
     * @param $request Request object
     * @return JsonResponse
     */
    public function sellOrdersSearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');
        $query = DB::table('events')->select('agents.name as agent_name', 'agents.agent_id', 'resources.name as resource_name', 'resources.id as resource_id', DB::raw('(events.timestamp) as timestamp'),
            DB::raw('events.new_credits as price'), 'objects.object_name', 'objects.object_id', DB::raw('(IFNULL(sqInventory.units,0)-events.new_quantity) AS volume'))
            ->leftJoin('agents', 'agents.agent_id', '=', 'events.drone')
            ->leftJoin('resources', 'events.resource', '=', 'resources.id')
            ->leftJoin('objects', 'objects.object_id', '=', 'agents.object_id')
            ->leftJoin('sqInventory', [['sqInventory.drone', '=', 'events.drone'], ['sqInventory.resource', '=', 'events.resource']])
            //->join(DB::raw('(SELECT MAX(events.id) as id FROM events WHERE events.event_type = ' . EventType::SELL_RESOURCE . ' GROUP BY events.drone, events.resource) AS events_latest'), 'events_latest.id', '=', 'events.id')
            ->join(DB::raw('(SELECT MAX(events.id) as id FROM events LEFT JOIN sqInventory ON sqInventory.drone = events.drone AND sqInventory.resource = events.resource
             WHERE events.event_type = ' . EventType::SELL_RESOURCE . ' AND new_quantity IS NOT NULL
             AND ((IFNULL(sqInventory.units,0)-events.new_quantity) > 0) GROUP BY events.drone, events.resource ORDER BY id DESC) AS events_latest'), 'events_latest.id', '=', 'events.id')/* ->where('events.event_type', '=', EventType::SELL_RESOURCE)
            ->where(DB::raw('(sqInventory.units-events.new_quantity) > 0 AND (sqInventory.units-events.new_quantity) IS NOT NULL'))*/
        ;
        if ($request->get('resource')) {
            $query->where('events.resource', $request->get('resource'));
        }
        if ($request->get('location'))
            $query->where('objects.object_name', $request->get('location'));
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else {
            if ($request->get('resource'))
                $query->orderBy('price', 'asc');
            else
                $query->orderBy('timestamp', 'desc');
        }
        /*  $query->groupBy('events.drone', 'events.resource');*/
        $orders = $query->paginate($resultsPerPage);

        return response()->json($orders, 200);
    }

    /**
     * Returns buy orders associated with a resource. Used by Vue table.
     * @param $request Request object
     * @param $id integer
     * @return JsonResponse
     * @throws MissingMandatoryParametersException
     */
   /* public function resourceOrders(Request $request, $id)
    {
        $type = $request->get('type');
        if (!$type)
            throw new MissingMandatoryParametersException("Type missing!");
        //top 10 buy orders
        $query = Event::where('event_type', '=', $type)->where('events.resource', $id)->with('agent:agent_id,object_id,name')->with('resource')
            ->leftJoin('sqInventory', [['sqInventory.drone', '=', 'events.drone'], ['sqInventory.resource', '=', 'events.resource']])
            ->with('agentObject')
            ->orderBy('new_credits', 'desc');
        if ($request->get('location'))
            $query->where('agent.object_id', $request->get('location'));
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else
            $query->orderBy('events.timestamp', 'desc');

        $orders = $query->paginate(10);

        return response()->json($orders, 200);
    }*/
}
