<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware(['auth:api', 'throttle:60,1'])->get('/user', function (Request $request) {
    return $request->user();
});

/*
| The game's JSON API (#380), /api/v1: what the website shows, for programs
| (players' tools and AI agents). No account, like the website; reads are
| limited to 120 a minute per address and releases and test runs to 10.
| Documented in documents/api.md.
*/
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:api-read')->get('clock', 'ApiController@clock');
    Route::middleware('throttle:api-read')->get('companies', 'ApiController@company');
    Route::middleware('throttle:api-read')->get('companies/{id}', 'ApiController@company')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('drones/{id}', 'ApiController@drone')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('orders', 'ApiController@orders');
    Route::middleware('throttle:api-read')->get('trades', 'ApiController@trades');
    Route::middleware('throttle:api-read')->get('history', 'ApiController@history');
    Route::middleware('throttle:api-read')->get('leaderboard', 'ApiController@leaderboard');
    Route::middleware('throttle:api-read')->get('places', 'ApiController@places');
    Route::middleware('throttle:api-read')->get('resources', 'ApiController@resources');
    Route::middleware('throttle:api-read')->get('protocols', 'ApiController@protocols');
    Route::middleware('throttle:api-read')->get('queries', 'ApiController@queries');
    Route::middleware('throttle:api-read')->get('test/{token}', 'ApiController@testRun');
    Route::middleware('throttle:api-read')->post('check', 'ApiController@check');
    Route::middleware('throttle:api-write')->post('release', 'ApiController@release');
    Route::middleware('throttle:api-write')->post('test', 'ApiController@test');
});
