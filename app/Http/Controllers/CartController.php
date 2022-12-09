<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    //

    public function AddToCart(Request $request){

        $userdata = $request->user();

        try{ 
            $tiles = $request->tiles;

            foreach($tiles as $tile){

                $Cart = new Cart();    
                $Cart->user_id  = $userdata['id'];
                $Cart->lat      = $tile['lat'];
                $Cart->lng      = $tile['lng'];
                 
                $Cart->save();

            }

            $data['message'] = "Tiles added to cart.";
            return response()->json(['status' => true, 'data' => $data]);

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function GetCartByUser(Request $request){

        $userdata = $request->user();

        try{ 
            $tiles = Cart::where('user_id',$userdata['id'])->get();

            if($tiles->IsNotEmpty()){
                foreach($tiles as $key => $tile){
                    $data['tiles'][$key]['lat']	= $tile->lat;
                    $data['tiles'][$key]['lng']	= $tile->lng;
                }
            }else{
                $data['tiles'] = array();
            }

            return response()->json(['status' => true, 'data' => $data]);

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function RemoveUserCart(Request $request){

        $userdata = $request->user();

        try{ 
            $tiles = Cart::where('user_id', $userdata['id'])->delete();

            if($tiles){
                $data['message'] = "Cart Successfully updated";
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                $data['message'] = "Something went wrong";
                return response()->json(['status' => false, 'data' => $data]);
            }
            
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    
}
