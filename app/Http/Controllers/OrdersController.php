<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Orders;
use App\Models\UserBoxes;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    //

    public function BuyNow(Request $request){

        $userdata = $request->user();
        $user_id = $userdata['id'];

        try{ 
            $tiles = Cart::where('user_id',$user_id)->get();

                $order = new Orders();    
                $order->user_id         = $user_id;
                $order->no_of_tiles     = count($tiles);
                $order->amount          = count($tiles) * 0.1;
                $order->next_due_date   = date("Y-m-d h:i:s", strtotime( date('Y-m-d')." +1 month" ));
                $order->next_expiry     = date("Y-m-d h:i:s", strtotime( $order->next_due_date." +10 day" ));

                if($order->save()){
                    foreach($tiles as $tile){

                        Cart::where('lat', $tile['lat'])->where('lng', $tile['lng'])->delete();

                        $UserBoxes = new UserBoxes();    
                        $UserBoxes->order_id  = $order->id;
                        $UserBoxes->lat       = $tile['lat'];
                        $UserBoxes->lng       = $tile['lng'];
                        $UserBoxes->price     = "0.1";

                        
                        $UserBoxes->save();

                    }

                    $data['message'] = "Tiles purchased successfully.";
                    return response()->json(['status' => true, 'data' => $data]);
                }else{
                    $data['message'] = "Something went wrong.";
                    return response()->json(['status' => false, 'data' => $data]);
                }

            

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UpdateCustomDetails(Request $request){

        $userdata = $request->user();

        try{ 
            $custom_details = $request->custom_details;

            $Orders = Orders::findorFail($request->order_id);
            
            if(!empty($Orders)){
                if($Orders->user_id == $userdata['id']){
                    $Orders->custom_details  = $custom_details;
                    $Orders->save();
                    $data['message'] = "Content Updated Successfully.";
                    return response()->json(['status' => true, 'data' => $data]);
                }else{
                    $data['message'] = "Something went wrong.";
                    return response()->json(['status' => false, 'data' => $data]);
                }
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UpdateSalePrice(Request $request){

        $userdata = $request->user();

        try{ 
            $sale_price = $request->sale_price;

            $Orders = Orders::findorFail($request->order_id);
            
            if(!empty($Orders)){
                if($Orders->user_id == $userdata['id']){
                    $Orders->sale_price  = $sale_price;

                    if($request->on_sale == true){
                        $Orders->on_sale  = "1";
                    }else{
                        $Orders->on_sale  = "0";
                    }

                    $Orders->save();
                    $data['message'] = "Sale price successfully updated.";
                    return response()->json(['status' => true, 'data' => $data]);
                }else{
                    $data['message'] = "Something went wrong.";
                    return response()->json(['status' => false, 'data' => $data]);
                }
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function SaleList(Request $request){

        try{ 

            $Orders = Orders::GetUserTiles();
            
            if(!empty($Orders)){

                foreach($Orders as $key => $order){
                    $data['tiles'][$key]['id'] = $order->id;
                    $tilesdata = UserBoxes::where('order_id', $order->id)->firstOrFail();
                    $data['tiles'][$key]['image']	    = (!empty($tilesdata->image)) ? env('APP_URL').'assets/uploads/defaultimages/'.$tilesdata->image : "https://via.placeholder.com/150"; 
                    $data['tiles'][$key]['user_id'] = $order->user_id;
                    $data['tiles'][$key]['username'] = $order->username;
                    $data['tiles'][$key]['no_of_tiles'] = $order->no_of_tiles;
                    $data['tiles'][$key]['custom_details'] = $order->custom_details;
                    $data['tiles'][$key]['amount'] = $order->amount;
                    $data['tiles'][$key]['sale_price'] = $order->sale_price;
                }

                if(!empty($data)){
                    return response()->json(['status' => true, 'data' => $data]);
                }else{
                    $data['tiles'] = array();
                    return response()->json(['status' => true, 'data' => $data]);
                }
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function MyPlaces(Request $request){

        try{ 

            $userdata = $request->user();

            $Orders = Orders::GetUserTilesByID($userdata['id']);
            
            if(!empty($Orders)){

                foreach($Orders as $key => $order){
                    $data['tiles'][$key]['id'] = $order->id;
                    $tilesdata = UserBoxes::where('order_id', $order->id)->firstOrFail();
                    $data['tiles'][$key]['image']	    = (!empty($tilesdata->image)) ? env('APP_URL').'assets/uploads/defaultimages/'.$tilesdata->image : "https://via.placeholder.com/150"; 
                    $data['tiles'][$key]['user_id'] = $order->user_id;
                    $data['tiles'][$key]['username'] = $order->username;
                    $data['tiles'][$key]['no_of_tiles'] = $order->no_of_tiles;
                    $data['tiles'][$key]['custom_details'] = $order->custom_details;
                    $data['tiles'][$key]['amount'] = $order->amount;
                    $data['tiles'][$key]['sale_price'] = $order->sale_price;
                }

                if(!empty($data)){
                    return response()->json(['status' => true, 'data' => $data]);
                }else{
                    $data['tiles'] = array();
                    return response()->json(['status' => true, 'data' => $data]);
                }
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    
}
