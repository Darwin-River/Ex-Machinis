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
// The first-startup guide (#719); the players' manual moved to /manual, and
// the guide sends an old /getting-started#anchor link on to the manual
Route::get('/getting-started', 'HomeController@firstStartup');
Route::get('/manual', 'HomeController@playersManual');
// Every badge described, each at its own anchor (#1055)
Route::get('/badges', 'HomeController@badges');
Route::get('/manual/images/{file}', 'HomeController@playersManualImage')->where('file', '[A-Za-z0-9_-]+\.(png|jpg|jpeg|gif|svg)');
Route::get('/getting-started/images/{file}', 'HomeController@oldManualImage')->where('file', '[A-Za-z0-9_-]+\.(png|jpg|jpeg|gif|svg)');
Route::redirect('/players-manual', '/manual', 301);
Route::redirect('/getting-started/manual', '/manual', 301);
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
// The House's demand and supply, from the Market page (#721)
Route::get('/market/house', 'WorldController@house');
Route::get('/market/history', 'WorldController@history');
Route::get('/market/shares', 'WorldController@shares');
// Loans (#928): public companies' loan requests, and backing them
Route::get('/market/loans', 'LoanController@index');
// The Trading desk (#779): watch list, a company's book as a ladder, an order ticket, your shares and your trading script
Route::get('/market/trade', 'PlayerShareController@desk');
// The order ticket's quote, for the page's script (#845): a visitor sees it too
Route::get('/companies/{id}/share-quote', 'PlayerShareController@quote')->whereNumber('id')->middleware('throttle:60,1');
// The economy dashboard (#698)
Route::get('/dashboard', 'WorldController@dashboard');
// The stock exchange's old short address (#692: GET /shares was a 404)
Route::redirect('/shares', '/market/shares', 301);
Route::get('/map', 'WorldController@map');
Route::get('/leaderboard', 'WorldController@leaderboard');
Route::get('/lineage', 'WorldController@lineage');
// The tech map (#804): every manufacturing pathway, recipe and tool
Route::get('/technology', 'WorldController@technology');
Route::redirect('/tech-map', '/technology', 301);
// Startups were seedlings until #855: old links and printed worksheets still
// work, and /startups lands on the race (#868)
Route::redirect('/startups', '/startups/race', 301);
Route::redirect('/seedlings', '/startups/race', 301);
Route::redirect('/seedlings/race', '/startups/race', 301);
Route::redirect('/first-seedling', '/getting-started', 301);
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
    // A forgotten password (#910): a link to the verified address, good for an hour, once
    Route::get('/password/forgot', 'AccountController@showForgot');
    Route::post('/password/forgot', 'AccountController@forgot')->middleware('throttle:20,1');
    Route::get('/password/reset/{id}/{hash}', 'AccountController@showReset')->whereNumber('id')
        ->middleware('signed:relative')->name('password.reset');
    Route::post('/password/reset/{id}/{hash}', 'AccountController@reset')->whereNumber('id')
        ->middleware(['signed:relative', 'throttle:20,1']);
    Route::get('/account', 'AccountController@show');
    Route::post('/account/token', 'AccountController@token');
    Route::get('/account/verify/{id}/{hash}', 'AccountController@verify')->whereNumber('id')
        ->middleware('signed:relative')->name('account.verify');
    Route::post('/account/verify', 'AccountController@resendVerification');
    Route::post('/account/classes', 'AccountController@createClassGroup');
    Route::post('/account/bot-keys', 'AccountController@makeBotKey');
    Route::post('/account/bot-keys/{id}/revoke', 'AccountController@revokeBotKey')->whereNumber('id');
    Route::get('/account/cohort-feedback', 'AccountController@cohortFeedback');
    Route::get('/account/classes/{id}', 'AccountController@classGroup')->whereNumber('id');
    Route::post('/account/classes/{id}/reset', 'AccountController@resetPupil')->whereNumber('id');
    // Opt-in e-mail notices (#761)
    Route::post('/account/notices', 'AccountController@notices');
    Route::post('/account/classes/{id}/notices', 'AccountController@classNotices')->whereNumber('id');
    Route::post('/account/classes/{id}/settings', 'AccountController@classSettings')->whereNumber('id');
    Route::post('/account/alerts', 'AccountController@addAlert');
    Route::post('/account/alerts/{id}/remove', 'AccountController@removeAlert')->whereNumber('id');
    Route::get('/notices/unsubscribe/{id}', 'AccountController@unsubscribe')->whereNumber('id')
        ->middleware('signed:relative')->name('notices.unsubscribe');
    // Loans (#928)
    Route::post('/loans/{id}/back', 'LoanController@back')->whereNumber('id');
    // A founder's note (#998)
    Route::post('/companies/{id}/note', 'UserController@note')->whereNumber('id');
    // Startups (#637)
    Route::post('/startups/claim', 'StartupController@claim');
    Route::post('/startups/{id}/push', 'StartupController@reprogram')->whereNumber('id');
    // A test drive of the draft: what its first drone would do, changing nothing (#1007)
    Route::post('/startups/{id}/test-drive', 'StartupController@testDrive')->whereNumber('id');
    Route::post('/startups/{id}/close', 'StartupController@close')->whereNumber('id');
    Route::post('/startups/{id}/mature', 'StartupController@mature')->whereNumber('id');
    // Player boards (#640)
    Route::get('/players', 'PlayerBoardController@show');
    // A player's public page (#905)
    Route::get('/players/{name}', 'PlayerBoardController@player')->where('name', '[^/]+');
    // The startup race (#776)
    Route::get('/startups/race', 'StartupController@race');
    // Players' shares and the bank (#638)
    Route::post('/companies/{id}/bank/sell', 'PlayerShareController@sellToBank')->whereNumber('id');
    Route::post('/companies/{id}/bank/buy', 'PlayerShareController@buyFromBank')->whereNumber('id');
    Route::post('/companies/{id}/share-orders', 'PlayerShareController@order')->whereNumber('id');
    // New shares to fund growth (#780)
    Route::post('/companies/{id}/offering', 'PlayerShareController@offer')->whereNumber('id');
    Route::post('/companies/{id}/offering/withdraw', 'PlayerShareController@withdrawOffer')->whereNumber('id');
    Route::post('/companies/{id}/offering/buy', 'PlayerShareController@buyOffered')->whereNumber('id');
    Route::post('/share-orders/{order}/cancel', 'PlayerShareController@cancel')->whereNumber('order');
    // The order ticket's Buy now / Sell now, and moving an order's price (#845)
    Route::post('/companies/{id}/share-now', 'PlayerShareController@now')->whereNumber('id');
    Route::post('/share-orders/{order}/move', 'PlayerShareController@move')->whereNumber('order');
    // The account trading script (#768)
    Route::post('/account/trade-script', 'PlayerShareController@saveTradeScript');
    // "Since you last looked" (#771): seen
    Route::post('/account/seen', 'AccountController@seen');
    Route::post('/account/trade-script/run', 'PlayerShareController@runTradeScript');
    // A test drive: what it would do, applying nothing (#1005)
    Route::post('/account/trade-script/test-drive', 'PlayerShareController@testDriveTradeScript');
    // The Player Ladder (#1039): try a drone with no account (#1042), keep it (#1041),
    // and a first share and a first loan (#1044)
    Route::get('/try', 'TryController@show');
    Route::post('/try', 'TryController@drive');
    Route::post('/try/keep', 'TryController@keep');
    Route::get('/market/shares/first', 'FirstStepsController@share');
    Route::get('/market/loans/first', 'FirstStepsController@loan');
});
Route::get('/release', 'ReleaseController@show');
Route::post('/release', 'ReleaseController@store');

// The scheduler's tick (#367 step 5); /get-mails is its old name
Route::get('/tick/{key}', 'HomeController@tick');
// The House's orders, in their own fast loop (#764)
Route::get('/house/{key}', 'HomeController@house');
// The scheduler's keeper (#738): rebuilds the dashboard and the stock exchange
Route::get('/keep/{key}', 'HomeController@keep');
Route::get('/get-mails/{key}', 'HomeController@tick');
