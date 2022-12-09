<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ReportTextController;

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
    return view('auth.login'); 
});

Route::any('/CreateProd', 'App\Http\Controllers\OrdersController@CreateProd');
Route::any('/CreatePlan', 'App\Http\Controllers\OrdersController@CreatePlan');
Route::any('/PurchaseTile/{id}/{userID}', 'App\Http\Controllers\OrdersController@PurchaseTile');
Route::any('/GetProdById', 'App\Http\Controllers\OrdersController@GetProdById');
Route::any('/GetPlanById', 'App\Http\Controllers\OrdersController@GetPlanById');
Route::any('/GetAllProducts', 'App\Http\Controllers\OrdersController@GetAllProducts');

Route::any('/GetSubscriptionsDetails', 'App\Http\Controllers\OrdersController@GetSubscriptionsDetails');

Route::any('/TransactionCompleted/{id}/{type}/{userID}', 'App\Http\Controllers\OrdersController@TransactionCompleted');

Auth::routes();

Route::group(['middleware' => ['auth']], function () { 

    Route::get('/home/{type?}/{date?}', [HomeController::class, 'index'])->name('home');

    Route::any('/Users',    [UsersController::class, 'index'])->name('Users');
    Route::any('/CreateUser/{id?}', [UsersController::class, 'CreateUser'])->name('CreateUser');
    Route::any('/StoreUser', [UsersController::class, 'StoreUser'])->name('StoreUser');
    Route::any('/DeleteUser/{id}', [UsersController::class, 'DeleteUser'])->name('DeleteUser');

    Route::any('/Subscriptions',    [OrdersController::class, 'index'])->name('Subscriptions');

    Route::any('/ViewTiles/{id}',    [OrdersController::class, 'ViewTiles'])->name('ViewTiles');

    Route::any('/ReportText',    [ReportTextController::class, 'ReportText'])->name('ReportText');
    Route::any('/CreateReportText/{id?}', [ReportTextController::class, 'CreateReportText'])->name('CreateReportText');
    Route::any('/StoreReportText', [ReportTextController::class, 'StoreReportText'])->name('StoreReportText');
    Route::any('/DeleteReportText/{id}', [ReportTextController::class, 'DeleteReportText'])->name('DeleteReportText');


    Route::get('/logout', [HomeController::class, 'logout']);

});