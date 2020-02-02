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

Auth::routes();

Route::get('/', 'HomeController@index')->name('Ex-Machinis');
Route::get('/home', 'HomeController@index')->name('home');

//static pages
Route::get('/forth-dictionary', 'HomeController@forthDictionary');
Route::get('/players-manual', 'HomeController@playersManual');
Route::get('/about-us', 'HomeController@aboutUs');

Route::post('/web-register', 'UserController@webRegistration');

Route::get('/queries', 'HomeController@queryCommands');

Route::get('/resources', 'ResourceController@index');
Route::get('/resources/{id}', 'ResourceController@view');

//space objects
Route::get('/objects', 'SpaceObjectController@index');
Route::get('/objects/search', 'SpaceObjectController@objectsSearch');
Route::get('/objects/{id}', 'SpaceObjectController@view');

//agents
Route::get('/spacecraft', 'AgentController@index');
Route::get('/spacecraft/search', 'AgentController@agentsSearch');
Route::get('/spacecraft/{id}', 'AgentController@view');
Route::get('/protocols', 'AgentController@protocols');

//companies
Route::get('/companies', 'UserController@companyIndex');
Route::get('/companies/search', 'UserController@companySearch');

//in-game events
Route::get('/events', 'HomeController@inGameEvents');
Route::get('/events/search', 'HomeController@InGameEventsSearch');


Route::get('/get-mails/{key}', 'HomeController@getMails');
Route::get('/user/send-report/{key}', 'UserController@reportCurrentUsersByMail');
