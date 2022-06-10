<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Cart;
use App\Models\Orders;
use App\Models\User;
use App\Models\UserBoxes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                $order->amount          = count($tiles) * 1;
                $order->next_due_date   = date("Y-m-d h:i:s", strtotime( date('Y-m-d')." +1 month" ));
                $order->next_expiry     = date("Y-m-d h:i:s", strtotime( $order->next_due_date." +10 day" ));

                if($order->save()){
                    

                    $data['id'] = $order->id;
                    $data['url'] = url('PurchaseTile/'. $order->id);
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
                    if($order->on_sale == "1"){
                        $data['tiles'][$key]['on_sale'] = true;
                    }else{
                        $data['tiles'][$key]['on_sale'] = false;
                    }

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

    public function PurchaseTile($id){

        $Orders = Orders::findorFail($id);
        $User = User::findorFail($Orders->user_id);

        if($Orders->sale_price){
            $quantity = $Orders->no_of_tiles * $Orders->sale_price;
        }else{
            $quantity = $Orders->no_of_tiles;
        }

        DB::beginTransaction();
        try {
            $data = [
                "plan_id" => env('PLAN_ID'),
                "quantity" => $quantity,
                "start_time" => Carbon::now()->addSeconds(10),
                "subscriber" => array('name' => array('given_name' => $User->fullname, 'surname' => $User->username), 'email_address' => $User->email),
                'application_context' => array('brand_name' => '' . env('APP_NAME') . ' Monthly Subscription', 'locale' => 'en-US', 'shipping_preference' => 'SET_PROVIDED_ADDRESS',
                    'user_action' => 'SUBSCRIBE_NOW', 'payment_method' =>
                        array('payer_selected' => 'PAYPAL', 'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED'),
                    'return_url' => '' . url('TransactionCompleted/'. $id) . '',
                    'cancel_url' => '' . url('TransactionCancel/'. $id) . '' )
            ];

            $paypal = new PaypalController();
            $subscribe = $paypal->subscribe($data);
            try {
                if (!isset($subscribe->debug_id)) {
                    foreach ($subscribe->links as $link) {
                        if ($link->rel == 'approve') {
                            return redirect($link->href);
                        }
                    }
                }
            } catch (\Exception $e) {
                
            }
        } catch (\Exception $e) {
            
            echo 'Something goes wrong'.$e;
        }
       
    }

    public function CreateProd(){
        $data = [
            "name" => "Flatmeta.io",
            "type" => "SERVICE",
            "description" => "flatmeta provides the land in a virtual world where u can buy and sale your land",
            "category" => "SERVICES",
            "home_url" => env('APP_URL'),
        ];

       $paypal = new PaypalController();
       $subscribe = $paypal->make_product($data);

       print_r($subscribe);
    }

    public function CreatePlan(){
        $data = [
            'product_id' => "PROD-6U229780257548608",
            'name' => "Flatmeta Plan",
            'quantity_supported' => true,
            'description' => "flatmeta provides the land in a virtual world where u can buy and sale your land"
        ];

        $data['billing_cycles'][] = [
            'frequency' => array('interval_unit' => "MONTH", 'interval_count' => 1),
            'tenure_type' => 'REGULAR',
            'sequence' =>  1,
            'total_cycles' => 12,
            "pricing_scheme" => array(
                "fixed_price" => array(
                    "value" => "0.01",
                    "currency_code" => "USD"
                )),
        ];
        $data['payment_preferences'] = [
            'auto_bill_outstanding' => true,
            'setup_fee' => array('value' => '0', 'currency_code' => 'USD'),
            'setup_fee_failure_action' => 'CONTINUE',
            'payment_failure_threshold' => 3
        ];
        $data['taxes'] = [
            'percentage' => '0',
            'inclusive' => true
        ];
        $paypal = new PaypalController();
        $package = $paypal->make_package($data);

        print_r($package);
    }

    public function GetProdById(){
        
        $paypal = new PaypalController();
        $package = $paypal->get_product('PROD-6U229780257548608');

        print_r($package);
    }

    public function GetPlanById(){
        
        $paypal = new PaypalController();
        $package = $paypal->get_plan('P-4V708803SV048851WMKPTV3A');

        print_r($package);
    }

    public function GetAllProducts(){
        
        $paypal = new PaypalController();
        $package = $paypal->get_all_product();

        print_r($package);
    }

    public function GetSubscriptionsDetails(){
        
        $paypal = new PaypalController();
        $package = $paypal->get_subscription('I-KU7MXH8WSP1H');

        print_r($package);
    }

    public function TransactionCompleted(Request $request,$id){

        if(!empty($request['subscription_id'])){
            $paypal = new PaypalController();
            $subscription_response = $paypal->get_subscription($request['subscription_id']);

            if($subscription_response->status == 'ACTIVE'){
                $Orders = Orders::findorFail($id);
                $Orders->status  = "1";
                $Orders->subscription_id  = $request['subscription_id'];
                $Orders->log  = json_encode($subscription_response);

                $Orders->save();

                $tiles = Cart::where('user_id',$Orders->user_id)->get();
                    foreach($tiles as $tile){

                        Cart::where('lat', $tile['lat'])->where('lng', $tile['lng'])->delete();

                        $UserBoxes = new UserBoxes();    
                        $UserBoxes->order_id  = $Orders->user_id;
                        $UserBoxes->lat       = $tile['lat'];
                        $UserBoxes->lng       = $tile['lng'];
                        $UserBoxes->price     = "1";

                        
                        $UserBoxes->save();

                    }

                $data['message'] = "Transaction Completed Successfully.";
                return response()->json(['status' => true, 'data' => $data]);
            }
        }
    }

    public function TransactionCancel(Request $request,$id){
        
        $Orders = Orders::findorFail($id);
            
            if(!empty($Orders)){
               
                $Orders = Orders::findorFail($id);
                $Orders->status  = "2";
                $Orders->save();

                $data['message'] = "Transaction Cancel. Please Try Again.";
                return response()->json(['status' => true, 'data' => $data]);
            }

    }

    public function UpdatePlanPricing(Request $request,$id){
        
        
        
    }

    
    
}
