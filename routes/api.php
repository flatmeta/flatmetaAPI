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
$router->post('/GetAllUser','App\Http\Controllers\UsersController@GetAllUser');
## User Boxes  ##
$router->get('/PurchasedTiles','App\Http\Controllers\UserBoxesController@PurchasedTiles');
$router->get('/RemoveUserCart','App\Http\Controllers\CartController@RemoveUserCart');

$router->get('/GetUserTilesByOrderId/{id}','App\Http\Controllers\UsersController@GetUserTilesByOrderId');

## User Boxes  ##

$router->get('/BoxImages','App\Http\Controllers\ImageController@BoxImages');


Route::middleware('auth:api')->group(function () use ($router) {

    

    ## User ##
    $router->get('/getuserbytoken','App\Http\Controllers\UsersController@GetUserDetails');
    $router->post('/UpdateUser','App\Http\Controllers\UsersController@UpdateUser');
    $router->post('/UploadUserImage','App\Http\Controllers\UsersController@UploadUserImage');

   
    
    ## User ##

    ## Cart ##
    $router->post('/AddToCart','App\Http\Controllers\CartController@AddToCart');
    $router->get('/GetCartByUser','App\Http\Controllers\CartController@GetCartByUser');
    $router->get('/RemoveUserCart','App\Http\Controllers\CartController@RemoveUserCart');
    
    ## Cart ##

    ## Order ##
    $router->get('/BuyNow','App\Http\Controllers\OrdersController@BuyNow');
    $router->post('/UpdateCustomDetails','App\Http\Controllers\OrdersController@UpdateCustomDetails');
    $router->post('/UpdateSalePrice','App\Http\Controllers\OrdersController@UpdateSalePrice');
    $router->get('/SaleList','App\Http\Controllers\OrdersController@SaleList');
    ## Order ##

    ## User Boxes  ##
    $router->post('/UpdateBoxImage','App\Http\Controllers\ImageController@UpdateBoxImage');
    $router->post('/UploadBoxesImage','App\Http\Controllers\ImageController@UploadBoxesImage');
    ## User Boxes  ##

    ## User Followers  ##
    $router->post('/SendFriendRequest','App\Http\Controllers\UserFollowersController@SendFriendRequest');
    $router->get('/GetAllFriendRequest','App\Http\Controllers\UserFollowersController@GetAllFriendRequest');
    $router->post('/UpdateFriendRequestStatus','App\Http\Controllers\UserFollowersController@UpdateFriendRequestStatus');
    ## User Followers  ##
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
