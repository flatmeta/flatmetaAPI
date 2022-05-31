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

           // if(!empty($tilesData)){
            if($tilesData->isNotEmpty()){
                foreach($tilesData as $key => $tiles){
                    $data['tiles'][$key]['user_id']	    = $tiles->user_id;
                    $data['tiles'][$key]['full_name']	= $tiles->full_name;
                    $data['tiles'][$key]['order_id']	= $tiles->order_id;
                    $data['tiles'][$key]['lat']	        = $tiles->lat;
                    $data['tiles'][$key]['lng']	        = $tiles->lng;
                    $data['tiles'][$key]['image']	    = (!empty($tiles->image)) ? env('APP_URL').'assets/uploads/defaultimages/'.$tiles->image : ""; 
                    $data['tiles'][$key]['custom_details']	= (!empty($tiles->custom_details)) ? $tiles->custom_details : ""; 
                }

                return response()->json(['status' => true, 'data' => $data]);

            }else{
                $data['tiles'] = array();
                return response()->json(['status' => true, 'data' => $data]);
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
