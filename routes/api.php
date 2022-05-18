<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

$router->post('/login','App\Http\Controllers\PassportAuthController@login');
$router->post('/register','App\Http\Controllers\PassportAuthController@register');

## User Boxes  ##
$router->get('/PurchasedTiles','UserBoxesController@PurchasedTiles');
$router->get('/RemoveUserCart','CartController@RemoveUserCart');

## User Boxes  ##

$router->get('/BoxImages','ImageController@BoxImages');


Route::middleware('auth:api')->group(function () use ($router) {
    ## User ##
    $router->get('/getuserbytoken','UsersController@GetUserDetails');
    $router->post('/UpdateUser','UsersController@UpdateUser');
    $router->post('/UploadUserImage','UsersController@UploadUserImage');

    ## User ##

    ## Cart ##
    $router->post('/AddToCart','CartController@AddToCart');
    $router->get('/GetCartByUser','CartController@GetCartByUser');
    $router->get('/RemoveUserCart','CartController@RemoveUserCart');
    
    ## Cart ##

    ## Order ##
    $router->get('/BuyNow','OrdersController@BuyNow');
    $router->post('/UpdateCustomDetails','OrdersController@UpdateCustomDetails');
    $router->post('/UpdateSalePrice','OrdersController@UpdateSalePrice');
    $router->get('/SaleList','OrdersController@SaleList');
    ## Order ##

    ## User Boxes  ##
    $router->post('/UpdateBoxImage','ImageController@UpdateBoxImage');
    $router->post('/UploadBoxesImage','ImageController@UploadBoxesImage');
    ## User Boxes  ##

    ## User Followers  ##
    $router->post('/SendFriendRequest','UserFollowersController@SendFriendRequest');
    $router->get('/GetAllFriendRequest','UserFollowersController@GetAllFriendRequest');
    $router->post('/UpdateFriendRequestStatus','UserFollowersController@UpdateFriendRequestStatus');
    ## User Followers  ##
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
