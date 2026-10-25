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
Route::get('/forth-dictionary', 'HomeController@forthDictionary');
Route::get('/getting-started', 'HomeController@playersManual');
Route::get('/getting-started/images/{file}', 'HomeController@playersManualImage')->where('file', '[A-Za-z0-9_-]+\.(png|jpg|jpeg|gif|svg)');
Route::get('/about-us', 'HomeController@aboutUs');

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

//in-game events
Route::get('/events', 'HomeController@inGameEvents');
Route::get('/events/search', 'HomeController@InGameEventsSearch');


//releasing a script (#324 step 6)
Route::get('/market', 'WorldController@market');
Route::get('/map', 'WorldController@map');
Route::get('/leaderboard', 'WorldController@leaderboard');
Route::get('/machine', 'WorldController@machine');
Route::get('/release', 'ReleaseController@show');
Route::post('/release', 'ReleaseController@store');
Route::post('/release/test', 'ReleaseController@test');
Route::get('/release/test/{token}', 'ReleaseController@testRun');

// The scheduler's tick (#367 step 5); /get-mails is its old name
Route::get('/tick/{key}', 'HomeController@tick');
Route::get('/get-mails/{key}', 'HomeController@tick');
