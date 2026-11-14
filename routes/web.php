<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {

    return view('welcome');
});

// No player accounts in Public Forge (#367 step 4): no login or registration

Route::get('/', 'HomeController@index')->name('Ex-Machinis');
Route::get('/home', 'HomeController@index')->name('home');

//static pages
Route::get('/getting-started', 'HomeController@playersManual');
Route::get('/getting-started/images/{file}', 'HomeController@playersManualImage')->where('file', '[A-Za-z0-9_-]+\.(png|jpg|jpeg|gif|svg)');
Route::get('/about-us', 'HomeController@aboutUs');
Route::get('/support', 'HomeController@support');
Route::get('/blog', 'BlogController@index');
Route::get('/blog/feed', 'BlogController@feed');
Route::get('/blog/{slug}', 'BlogController@show')->where('slug', '[a-z0-9-]+');

Route::get('/queries', 'HomeController@queryCommands');
Route::get('/queries/{id}', 'HomeController@queryCommand')->whereNumber('id');

Route::get('message', 'HomeController@message');

//resources
Route::get('/resources', 'ResourceController@index');
Route::get('/resources/{id}', 'ResourceController@view')->whereNumber('id');
Route::get('/buys', 'ResourceController@buyOrders');
Route::get('/buys/search', 'ResourceController@buyOrdersSearch');
Route::get('/sells', 'ResourceController@sellOrders');
Route::get('/sells/search', 'ResourceController@sellOrdersSearch');
/*Route::get('/resource-orders/{id}', 'ResourceController@resourceOrders');*/

//space objects
Route::get('/locations', 'SpaceObjectController@index');
Route::get('/objects/search', 'SpaceObjectController@objectsSearch');
Route::get('/locations/{id}', 'SpaceObjectController@view')->whereNumber('id');

//agents
Route::get('/spacecraft', 'AgentController@index');
Route::get('/spacecraft/search', 'AgentController@agentsSearch');
Route::get('/spacecraft/{id}', 'AgentController@view')->whereNumber('id');
Route::get('/protocols', 'AgentController@protocols');
Route::get('/protocols/{id}', 'AgentController@protocol')->whereNumber('id');

//companies
Route::get('/companies', 'UserController@companyIndex');
Route::get('/companies/search', 'UserController@companySearch');
Route::get('/companies/{id}', 'UserController@companyView')->whereNumber('id');
Route::post('/companies/{id}/push', 'UserController@push')->whereNumber('id');

//in-game events
Route::get('/events', 'HomeController@inGameEvents');
Route::get('/events/search', 'HomeController@InGameEventsSearch');


//releasing a script (#324 step 6)
Route::get('/market', 'WorldController@market');
Route::get('/market/history', 'WorldController@history');
Route::get('/market/shares', 'WorldController@shares');
Route::get('/map', 'WorldController@map');
Route::get('/leaderboard', 'WorldController@leaderboard');
Route::get('/lineage', 'WorldController@lineage');
Route::get('/machine/queries', 'WorldController@machineQueries');
Route::get('/machine/protocols', 'WorldController@machineProtocols');
Route::get('/machine/query', 'WorldController@machineQuery')->middleware('throttle:240,1');
// Player accounts (#636): only while the new rules (#635) are on
Route::middleware('new-rules')->group(function () {
    Route::get('/signup', 'AccountController@showSignup');
    Route::post('/signup', 'AccountController@signup');
    Route::get('/login', 'AccountController@showLogin')->name('login');
    Route::post('/login', 'AccountController@login')->middleware('throttle:20,1');
    Route::post('/logout', 'AccountController@logout');
    Route::get('/account', 'AccountController@show');
    Route::post('/account/token', 'AccountController@token');
    Route::get('/account/verify/{id}/{hash}', 'AccountController@verify')->whereNumber('id')
        ->middleware('signed:relative')->name('account.verify');
    Route::post('/account/verify', 'AccountController@resendVerification');
    Route::post('/account/classes', 'AccountController@createClassGroup');
    Route::get('/account/classes/{id}', 'AccountController@classGroup')->whereNumber('id');
    Route::post('/account/classes/{id}/reset', 'AccountController@resetPupil')->whereNumber('id');
    // Seedlings (#637)
    Route::post('/seedlings/claim', 'SeedlingController@claim');
    Route::post('/seedlings/{id}/push', 'SeedlingController@reprogram')->whereNumber('id');
    Route::post('/seedlings/{id}/close', 'SeedlingController@close')->whereNumber('id');
    Route::post('/seedlings/{id}/mature', 'SeedlingController@mature')->whereNumber('id');
    // Players' shares and the bank (#638)
    Route::post('/companies/{id}/bank/sell', 'PlayerShareController@sellToBank')->whereNumber('id');
    Route::post('/companies/{id}/bank/buy', 'PlayerShareController@buyFromBank')->whereNumber('id');
    Route::post('/companies/{id}/share-orders', 'PlayerShareController@order')->whereNumber('id');
    Route::post('/share-orders/{order}/cancel', 'PlayerShareController@cancel')->whereNumber('order');
});
Route::get('/release', 'ReleaseController@show');
Route::post('/release', 'ReleaseController@store');

// The scheduler's tick (#367 step 5); /get-mails is its old name
Route::get('/tick/{key}', 'HomeController@tick');
Route::get('/get-mails/{key}', 'HomeController@tick');
