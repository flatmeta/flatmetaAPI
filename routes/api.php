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

## Auth (rate limited: codes and passwords must not be guessable by brute force) ##
Route::middleware('throttle:10,1')->group(function () use ($router) {
    $router->post('/login', 'App\Http\Controllers\AuthController@login');
    $router->post('/register', 'App\Http\Controllers\AuthController@register');
    $router->post('/VerifyEmail', 'App\Http\Controllers\AuthController@verifyEmail');

    ## Password Recovery ##
    $router->post('/ForgetPassword', 'App\Http\Controllers\UsersController@ForgetPassword');
    $router->post('/VerifyForgetPasswordCode', 'App\Http\Controllers\UsersController@VerifyForgetPasswordCode');
    $router->post('/ChangeUserPassword', 'App\Http\Controllers\UsersController@ChangeUserPassword');
});

$router->post('/GetAllUser', 'App\Http\Controllers\UsersController@GetAllUser');

## User Boxes & Land Areas (read only) ##
$router->get('/PurchasedTiles', 'App\Http\Controllers\UserBoxesController@PurchasedTiles');
$router->get('/GetUserTilesByOrderId/{id}', 'App\Http\Controllers\UsersController@GetUserTilesByOrderId');
$router->get('/BoxImages', 'App\Http\Controllers\ImageController@BoxImages');
$router->get('/SaleList', 'App\Http\Controllers\OrdersController@SaleList');
$router->get('/LatestPurchasedTiles', 'App\Http\Controllers\OrdersController@LatestPurchasedTiles');
$router->get('/FindArea', 'App\Http\Controllers\OrdersController@FindArea');

## Leaderboard, Clans & Community Mini-Git-Lab (read only) ##
$router->get('/Leaderboard', 'App\Http\Controllers\OrdersController@Leaderboard');
$router->get('/CommunityProposals', 'App\Http\Controllers\OrdersController@CommunityProposals');

## Reporting ##
$router->get('/GetAllReportText', 'App\Http\Controllers\ReportTextController@GetAllReportText');

Route::middleware('auth:sanctum')->group(function () use ($router) {
    ## User ##
    $router->get('/getuserbytoken', 'App\Http\Controllers\UsersController@GetUserDetails');
    $router->post('/UpdateUser', 'App\Http\Controllers\UsersController@UpdateUser');
    $router->post('/UploadUserImage', 'App\Http\Controllers\UsersController@UploadUserImage');
    $router->post('/SaveImageFromUrl', 'App\Http\Controllers\UsersController@SaveImageFromUrl');

    ## Cart ##
    $router->post('/AddToCart', 'App\Http\Controllers\CartController@AddToCart');
    $router->get('/GetCartByUser', 'App\Http\Controllers\CartController@GetCartByUser');
    $router->get('/RemoveUserCart', 'App\Http\Controllers\CartController@RemoveUserCart');

    ## Order ##
    $router->get('/BuyNow', 'App\Http\Controllers\OrdersController@BuyNow');
    $router->post('/UpdateCustomDetails', 'App\Http\Controllers\OrdersController@UpdateCustomDetails');
    $router->post('/UpdateSalePrice', 'App\Http\Controllers\OrdersController@UpdateSalePrice');
    $router->get('/MyPlaces', 'App\Http\Controllers\OrdersController@MyPlaces');
    $router->post('/ClaimTiles', 'App\Http\Controllers\OrdersController@ClaimTiles');
    $router->post('/BuyMarketplaceOrder', 'App\Http\Controllers\BillingController@BuyMarketplaceOrder');
    $router->post('/StartBilling', 'App\Http\Controllers\BillingController@StartBilling');
    $router->get('/MyPayments', 'App\Http\Controllers\BillingController@MyPayments');
    $router->post('/ReleaseOrder', 'App\Http\Controllers\OrdersController@ReleaseOrder');
    $router->post('/UpdateCustomDetailsPublic', 'App\Http\Controllers\OrdersController@UpdateCustomDetails');

    ## User Boxes ##
    $router->post('/UpdateBoxImage', 'App\Http\Controllers\ImageController@UpdateBoxImage');
    $router->post('/RevertBoxImage', 'App\Http\Controllers\ImageController@RevertBoxImage');
    $router->post('/UploadBoxesImage', 'App\Http\Controllers\ImageController@UploadBoxesImage');

    ## User Followers ##
    $router->post('/SendFriendRequest', 'App\Http\Controllers\UserFollowersController@SendFriendRequest');
    $router->get('/GetAllFriendRequest', 'App\Http\Controllers\UserFollowersController@GetAllFriendRequest');
    $router->get('/GetFriends', 'App\Http\Controllers\UserFollowersController@GetFriends');
    $router->post('/UpdateFriendRequestStatus', 'App\Http\Controllers\UserFollowersController@UpdateFriendRequestStatus');
    $router->post('/GetRoomId', 'App\Http\Controllers\UserFollowersController@GetRoomId');

    ## Chat Controller ##
    $router->get('/GetAllMessagesByRoomId/{id}', 'App\Http\Controllers\ChatController@GetAllMessagesByRoomId');
    $router->post('/AddNewMessaage', 'App\Http\Controllers\ChatController@AddNewMessaage');
    $router->post('/AddUserReport', 'App\Http\Controllers\UserReportController@AddUserReport');

    ## Community Mini-Git-Lab ##
    $router->post('/SubmitCommunityProposal', 'App\Http\Controllers\OrdersController@SubmitCommunityProposal');
    $router->post('/VoteCommunityProposal', 'App\Http\Controllers\OrdersController@VoteCommunityProposal');
});

## PayPal webhook (verified with PayPal's signature check, no user token) ##
$router->post('/paypal/webhook', 'App\Http\Controllers\BillingController@webhook');
