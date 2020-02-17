<?php

namespace App\Http\Controllers;

use App\EventType;
use App\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

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


    public function view($id)
    {

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
        $resultsPerPage = Config::get('constants.options.results_per_page');
        $query = DB::table('events')->select('agents.name as agent_name', 'agents.agent_id', 'resources.name as resource_name', 'resources.id as resource_id', 'events.timestamp',
            'events.new_credits as price', 'objects.object_name', 'objects.object_id', DB::raw('(events.new_quantity-sqInventory.units) AS volume'))
            ->leftJoin('agents', 'agents.agent_id', '=', 'events.drone')
            ->leftJoin('resources', 'events.resource', '=', 'resources.id')
            ->leftJoin('objects', 'objects.object_id', '=', 'agents.object_id')
            ->leftJoin('sqInventory', 'sqInventory.drone', '=', 'events.drone')
            ->where('events.event_type', '=', EventType::BUY_RESOURCE);
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else
            $query->orderBy('timestamp', 'desc');
        $orders = $query->paginate($resultsPerPage);
        // var_dump($spaceObjects);exit;

        return response()->json($orders, 200);;

    }
}
