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

//pages
Route::get('/forth-dictionary', 'HomeController@forthDictionary');

Route::post('/web-register', 'UserController@webRegistration');

Route::get('/get-mails/{key}', 'HomeController@getMails');
Route::get('/user/send-report/{key}', 'UserController@reportCurrentUsersByMail');
