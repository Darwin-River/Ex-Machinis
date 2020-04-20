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
        $resources = Resource::orderBy('id', 'asc')->get();
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

        return view('resources.view', compact('resource'));
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
