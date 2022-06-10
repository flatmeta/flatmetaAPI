<?php

use Illuminate\Support\Facades\Route;

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


Route::any('/CreateProd', 'App\Http\Controllers\OrdersController@CreateProd');
Route::any('/CreatePlan', 'App\Http\Controllers\OrdersController@CreatePlan');
Route::any('/PurchaseTile/{id}', 'App\Http\Controllers\OrdersController@PurchaseTile');
Route::any('/GetProdById', 'App\Http\Controllers\OrdersController@GetProdById');
Route::any('/GetPlanById', 'App\Http\Controllers\OrdersController@GetPlanById');
Route::any('/GetAllProducts', 'App\Http\Controllers\OrdersController@GetAllProducts');

Route::any('/GetSubscriptionsDetails', 'App\Http\Controllers\OrdersController@GetSubscriptionsDetails');

Route::any('/TransactionCompleted/{id}/{type}', 'App\Http\Controllers\OrdersController@TransactionCompleted');

