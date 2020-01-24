<?php

namespace App\Http\Controllers;

use App\Resource;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    /**
     * Displays list of resources
     */
    public function index()
    {
        $resources = Resource::orderBy('id', 'asc')->get();
        return view('resources', compact('resources'));
    }


    public function view($id)
    {

    }
}
