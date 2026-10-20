<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\ReportTextController;
use App\Http\Controllers\UserReportController;

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

## PayPal sends buyers back here, then on to the app's /thankyou page ##
Route::get('/billing/subscription/return', 'App\Http\Controllers\BillingController@subscriptionReturn');
Route::get('/billing/subscription/cancel/{order}', 'App\Http\Controllers\BillingController@subscriptionCancel');
Route::get('/billing/sale/return', 'App\Http\Controllers\BillingController@saleReturn');
Route::get('/billing/sale/cancel', 'App\Http\Controllers\BillingController@saleCancel');

Auth::routes(['register' => false]);

Route::group(['middleware' => ['auth', 'admin']], function () { 

    ## One-off seeding ##
    Route::any('/AddNewImages', 'App\Http\Controllers\ImageController@AddNewImages');

    Route::get('/home/{type?}/{date?}', [HomeController::class, 'index'])->name('home');

    Route::any('/Users',    [UsersController::class, 'index'])->name('Users');
    Route::any('/CreateUser/{id?}', [UsersController::class, 'CreateUser'])->name('CreateUser');
    Route::any('/StoreUser', [UsersController::class, 'StoreUser'])->name('StoreUser');
    Route::any('/DeleteUser/{id}', [UsersController::class, 'DeleteUser'])->name('DeleteUser');

    Route::get('/Payments', [\App\Http\Controllers\PaymentsAdminController::class, 'index'])->name('Payments');
    Route::post('/Payments/{id}/retry', [\App\Http\Controllers\PaymentsAdminController::class, 'retryPayout'])->name('RetryPayout');

    Route::any('/Subscriptions',    [OrdersController::class, 'index'])->name('Subscriptions');

    Route::any('/ViewTiles/{id}',    [OrdersController::class, 'ViewTiles'])->name('ViewTiles');
    Route::any('/RevertOrderFromCMS/{id}', [OrdersController::class, 'RevertOrderFromCMS'])->name('RevertOrderFromCMS');
    Route::any('/RevokeOrderFromCMS/{id}', [OrdersController::class, 'RevokeOrderFromCMS'])->name('RevokeOrderFromCMS');

    Route::any('/ReportText',    [ReportTextController::class, 'ReportText'])->name('ReportText');
    Route::any('/CreateReportText/{id?}', [ReportTextController::class, 'CreateReportText'])->name('CreateReportText');
    Route::any('/StoreReportText', [ReportTextController::class, 'StoreReportText'])->name('StoreReportText');
    Route::any('/DeleteReportText/{id}', [ReportTextController::class, 'DeleteReportText'])->name('DeleteReportText');

    Route::any('/UserReports', [UserReportController::class, 'UserReports'])->name('UserReports');

    Route::get('/logout', [HomeController::class, 'logout']);

});