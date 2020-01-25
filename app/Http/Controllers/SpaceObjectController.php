<?php

namespace App\Http\Controllers;

use App\SpaceObject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Config;

class SpaceObjectController extends Controller
{
    /**
     * Space objects search engine
     */
    public function index()
    {
        return view('space-objects.index'/*, compact()*/);
    }

    /**
     * Used by search engine
     * @param $request Request object
     * @return JsonResponse
     */
    public function objectsSearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');

        $query = DB::table('objects')->select('objects.object_id as object_id', 'objects.object_name as object_name', 'objects.object_type as object_type', 'cb.object_name as central_body_name', 'cb.object_id as central_body_id')
            ->leftJoin('objects as cb', 'objects.central_body_object_id', '=', 'cb.object_id')/*with('centralBody')*/
        ;
        if ($request->get('object_id') !== null)
            $query->where('objects.object_id', intval($request->get('object_id')));
        if ($request->get('keyword') !== null)
            $query->where(function ($query) use ($request) {
                $query->where('objects.object_name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('objects.object_type', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('cb.object_name', 'LIKE', "%" . $request->get('keyword') . "%");
            });
        if ($request->get('object_type') !== null)
            $query->where('objects.object_type', $request->get('object_type'));
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        }
        $spaceObjects = $query->paginate($resultsPerPage);
        // var_dump($spaceObjects);exit;


        return response()->json(/*['data' => $spaceObjects, 'pagination' =>  $spaceObjects]*/ $spaceObjects, 200);
    }


    /**
     * Displays one space object
     */
    public function view()
    {
        return view('space-objects.view');
    }

}