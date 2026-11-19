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
| limited to 120 a minute per address and releases to 10.
| Documented in documents/api.md.
*/
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:api-read')->get('clock', 'ApiController@clock');
    // The economy dashboard (#698): worked out once a minute
    Route::middleware('throttle:api-read')->get('dashboard', 'ApiController@dashboard');
    Route::middleware('throttle:api-read')->get('companies', 'ApiController@company');
    Route::middleware('throttle:api-read')->get('companies/{id}', 'ApiController@company')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('drones/{id}', 'ApiController@drone')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('drones/{id}/timeline', 'ApiController@droneTimeline')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('orders', 'ApiController@orders');
    Route::middleware('throttle:api-read')->get('trades', 'ApiController@trades');
    Route::middleware('throttle:api-read')->get('history', 'ApiController@history');
    Route::middleware('throttle:api-read')->get('leaderboard', 'ApiController@leaderboard');
    Route::middleware('throttle:api-read')->get('places', 'ApiController@places');
    Route::middleware('throttle:api-read')->get('resources', 'ApiController@resources');
    Route::middleware('throttle:api-read')->get('protocols', 'ApiController@protocols');
    Route::middleware('throttle:api-read')->get('queries', 'ApiController@queries');
    // #380 round 36: recipes, the House's booms and slumps, net wealth by day
    Route::middleware('throttle:api-read')->get('recipes', 'ApiController@recipes');
    Route::middleware('throttle:api-read')->get('recipes/{resource}/tree', 'ApiController@recipeTree')->whereNumber('resource');
    Route::middleware('throttle:api-read')->get('news', 'ApiController@news');
    Route::middleware('throttle:api-read')->get('house-demand', 'ApiController@houseDemand');
    Route::middleware('throttle:api-read')->get('house-supply', 'ApiController@houseSupply');
    Route::middleware('throttle:api-write')->post('companies/{id}/push', 'ApiController@push')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('shares', 'ApiController@shares');
    Route::middleware('throttle:api-read')->get('shares/{id}/history', 'ApiController@shareHistory')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('companies/{id}/history', 'ApiController@companyHistory')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('companies/{id}/earnings', 'ApiController@companyEarnings')->whereNumber('id');
    // Events that explain the charts (#720): the world's, and a company's own
    Route::middleware('throttle:api-read')->get('world-events', 'ApiController@worldEvents');
    Route::middleware('throttle:api-read')->get('companies/{id}/events', 'ApiController@companyEvents')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('companies/{id}/lineage', 'ApiController@companyLineage')->whereNumber('id');
    Route::middleware('throttle:api-read')->get('lineage', 'ApiController@lineage');
    Route::middleware('throttle:api-read')->post('check', 'ApiController@check');
    Route::middleware('throttle:api-write')->post('release', 'ApiController@release');
    // Player accounts (#636): only while the new rules (#635) are on
    Route::middleware(['new-rules', 'throttle:api-write'])->post('accounts', 'AccountApiController@create');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('login', 'AccountApiController@login');
    Route::middleware(['new-rules', 'throttle:api-read'])->get('account', 'AccountApiController@show');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('account/verify', 'AccountApiController@resendVerification');
    // Startups (#637)
    Route::middleware(['new-rules', 'throttle:api-read'])->get('startups', 'StartupApiController@index');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('startups/claim', 'StartupApiController@claim');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('startups/{id}/push', 'StartupApiController@reprogram')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('startups/{id}/close', 'StartupApiController@close')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('startups/{id}/mature', 'StartupApiController@mature')->whereNumber('id');
    // Player boards and the digest (#640)
    Route::middleware(['new-rules', 'throttle:api-read'])->get('players', 'PlayerBoardController@api');
    // The startup race (#776)
    Route::middleware(['new-rules', 'throttle:api-read'])->get('startups/race', 'StartupController@raceApi');
    Route::middleware(['new-rules', 'throttle:api-read'])->get('account/digest', 'PlayerBoardController@digest');
    Route::middleware(['new-rules', 'throttle:api-read'])->get('account/records', 'PlayerBoardController@records');
    // Who a company deals with (#639)
    Route::middleware(['new-rules', 'throttle:api-read'])->get('companies/{id}/dealings', fn ($id) => response()->json(
        ['company' => (int) $id, 'dealings' => \App\Services\Dealings::of((int) $id)]))->whereNumber('id');
    // Players' shares and the bank (#638)
    Route::middleware(['new-rules', 'throttle:api-read'])->get('player-shares/{id}', 'PlayerShareController@apiCompany')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-read'])->get('account/shares', 'PlayerShareController@apiMine');
    // The account trading script (#768)
    Route::middleware(['new-rules', 'throttle:api-read'])->get('account/trade-script', 'PlayerShareController@apiTradeScript');
    Route::middleware(['new-rules', 'throttle:api-write'])->put('account/trade-script', 'PlayerShareController@apiSaveTradeScript');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('account/trade-script/run', 'PlayerShareController@apiRunTradeScript');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('player-shares/{id}/bank/sell', 'PlayerShareController@apiSellToBank')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('player-shares/{id}/bank/buy', 'PlayerShareController@apiBuyFromBank')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('player-shares/{id}/orders', 'PlayerShareController@apiOrder')->whereNumber('id');
    // New shares to fund growth (#780)
    Route::middleware(['new-rules', 'throttle:api-write'])->post('player-shares/{id}/offering', 'PlayerShareController@apiOffer')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('player-shares/{id}/offering/withdraw', 'PlayerShareController@apiWithdrawOffer')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('player-shares/{id}/offering/buy', 'PlayerShareController@apiBuyOffered')->whereNumber('id');
    Route::middleware(['new-rules', 'throttle:api-write'])->post('player-shares/orders/{order}/cancel', 'PlayerShareController@apiCancel')->whereNumber('order');
});
