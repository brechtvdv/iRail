<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

Route::get('/', 'RedirectController@index');
Route::get('/connections/', 'ConnectionsController@index');
Route::get('/liveboard/', 'LiveboardController@index');
Route::get('/stations/', 'StationsController@index');
Route::get('/vehicle/', 'VehicleController@index');
