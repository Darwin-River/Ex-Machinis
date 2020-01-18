<?php

namespace App\Http\Controllers;

use App\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class AgentController extends Controller
{
    /**
     * Agents search engine
     */
    public function index()
    {
        return view('spacecraft.index');
    }

    /**
     * Used by search engine to retrieve agents
     * @param $request Request object
     * @return JsonResponse
     */
    public function agentsSearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');
        $query = DB::table('agents')->select('agents.agent_id as agent_id', 'agents.name as name', 'hulls.id as hull_id', 'hulls.name as hull_name', 'users.name as owner_name'
            , 'users.user_id as owner_id',
            'objects.object_id AS location_id', 'objects.object_name AS location_name')
            ->leftJoin('hulls', 'agents.hull_type', '=', 'hulls.id')
            ->leftJoin('users', 'agents.user_id', '=', 'users.user_id')
            ->leftJoin('objects', 'agents.object_id', '=', 'objects.object_id');
        if ($request->get('agent_id') !== null)
            $query->where('agents.agent_id', intval($request->get('agent_id')));
        if ($request->get('agent_name') !== null)
            $query->where('agents.name', 'LIKE', "%" . $request->get('agent_name') . "%");
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        }
        $spaceObjects = $query->paginate($resultsPerPage);
        // var_dump($spaceObjects);exit;

        return response()->json($spaceObjects, 200);
    }


    /**
     * Displays one Agent
     */
    public function view()
    {
        return view('spacecraft.view');
    }

    /**
     * Displays list of protocols
     */
    public function protocols()
    {
        $protocols = Protocol::orderBy('id', 'asc')->get();
        return view('spacecraft.protocols', compact('protocols'));
    }
}
