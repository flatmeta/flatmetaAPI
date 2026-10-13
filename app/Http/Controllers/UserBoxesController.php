<?php

namespace App\Http\Controllers;

use App\Models\UserBoxes;
use Illuminate\Http\Request;

class UserBoxesController extends Controller
{
    //

    public function PurchasedTiles(Request $request){

        try{ 
            $tilesData = UserBoxes::GetUserTiles();

            $data = ['tiles' => []];
            $baseUrl = rtrim(config('app.url', url('/')), '/') . '/';

            if (!empty($tilesData) && $tilesData->isNotEmpty()) {
                foreach($tilesData as $key => $tiles){
                    $data['tiles'][] = [
                        'user_id' => $tiles->user_id,
                        'fullname' => $tiles->fullname,
                        'order_id' => $tiles->order_id,
                        'lat' => $tiles->lat,
                        'lng' => $tiles->lng,
                        'image' => (!empty($tiles->image)) ? $baseUrl.'assets/uploads/defaultimages/'.$tiles->image : '',
                        'custom_details' => (!empty($tiles->custom_details)) ? $tiles->custom_details : ''
                    ];
                }
            }

            return response()->json(['status' => true, 'data' => $data]);

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
