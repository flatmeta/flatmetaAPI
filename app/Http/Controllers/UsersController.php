<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Orders;
use App\Models\UserBoxes;
use Illuminate\Http\Request;
use App\Models\UserFollowers;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class UsersController extends Controller
{
    //

    public function GetUserDetails(Request $request){

        try{

            $userdata = $request->user();
           
            if(!empty($userdata)){

                $data['user_id']        =  $userdata['id'];
                $data['fullname']       =  $userdata['fullname'];
                $data['email']          =  $userdata['email'];
                $data['username']       =  $userdata['username'];
                
                $userfile_path = env('APP_URL').'assets/uploads/users/';

                if(empty($userdata['image'])){ 
                    $data['user_image'] = $userfile_path.'noimage.png';
                }else{
                    $data['user_image'] = $userfile_path.$userdata['image'];
                }
                
                
                $data['status']         =  $userdata['status'];
                
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                $data['messsage'] = "Please login to continue";

                return response()->json(['status' => false, 'data' => $data]);
            }
           
        }catch(BadRequestException $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

       
    }

    public function UpdateUser(Request $request){

        $userdata = $request->user();

        $user =  User::where('id', $userdata->id)->firstOrFail();

        try{
            $user->fullname =  $request->fullname;
            $user->image    =  $request->user_image;
            $user->username = strtolower(preg_replace('/[^0-9a-zA-Z]/', '', $request->username));
    
            if($user->save()){

                $updateduser =  User::where('id', $userdata->id)->firstOrFail();

                $data['user_id']        =  $updateduser['id'];
                $data['fullname']       =  $updateduser['fullname'];
                $data['email']          =  $updateduser['email'];
                $data['username']       =  $updateduser['username'];
                
                $userfile_path = env('APP_URL').'assets/uploads/users/';

                if(empty($updateduser['image'])){ 
                    $data['user_image'] = $userfile_path.'noimage.png';
                }else{
                    $data['user_image'] = $userfile_path.$updateduser['image'];
                }
                
                $data['status']         =  $updateduser['status'];


                return response()->json(['status' => true, 'data' => $data]);
            }
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UploadUserImage(Request $request){

        try{
            
            $userdt = $request->user();
            
            $user = new User();
            $userdata = $user::where('id', $userdt->id)->firstOrFail();

            $allowedfileExtension = ['svg','jpg','JPG','jpeg','png','gif'];
            $file = $request->file('images');

            $filename = 'fm_user'.date('Ymdhis').'_'.$file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $media_type = explode('/',$file->getMimeType());

            $check = in_array($extension, $allowedfileExtension);

            if ($check){
                
                $user = $user::findorFail($userdata['id']);

                $user->image = $filename;
                $user->save();

                $upload = $file->move('assets/uploads/users/',$filename); 
                
                $data['file_name'] = $filename;
                $data['user_image']  = env('APP_URL').$upload; 
                
                return response()->json(['status' => true, 'data' => $data]);

            }else{
                $data['message'] = "Unsupported Media Type";
                return response()->json(['status' => false, 'data' => $data]);
            }
           
        }catch(BadRequestException $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }

    public function GetAllUser(Request $request){

        try{

            $users = User::all();

            foreach($users as $key => $user){
                if(!empty($request->user_id)){  
                    $friends = UserFollowers::where('user_id',$request->user_id)->where('follower_user_id',$user->id)->where('status','!=','3')->first();
                    
                    if(empty($friends)){
                        $friends = UserFollowers::where('user_id',$user->id)->where('follower_user_id',$request->user_id)->where('status','!=','3')->first();
                    }
                }else{
                    $friends = "";
                }

                $data['users'][$key]['id'] = $user->id;
                $data['users'][$key]['fullname'] = $user->fullname;
                $data['users'][$key]['username'] = $user->username;

                $userfile_path = env('APP_URL').'assets/uploads/users/';

                if(empty($user->image)){ 
                    $data['users'][$key]['image'] = $userfile_path.'noimage.png';
                }else{
                    $data['users'][$key]['image'] = $userfile_path.$user->image;
                }

                if(empty($friends)){
                    $data['users'][$key]['friends'] = false;
                }else{
                    $data['users'][$key]['friends'] = true;
                }

                $data['users'][$key]['status'] = $user->status;

            }

            if(!empty($data)){
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                $data['users'] = array();
                return response()->json(['status' => true, 'data' => $data]);
            }

        }catch(BadRequestException $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }

    public function GetUserTilesByOrderId($id){

        try{

            $UserBoxes = UserBoxes::GetUserTilesByOrderId($id);

            foreach($UserBoxes as $key => $order){
                $data['tiles'][$key]['id'] = $order->id;
                $data['tiles'][$key]['lat'] = $order->lat;
                $data['tiles'][$key]['lng'] = $order->lng;
            }

            if(!empty($data)){
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                $data['tiles'] = array();
                return response()->json(['status' => true, 'data' => $data]);
            }
            
        }catch(BadRequestException $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }

    public function ForgetPassword(Request $request){

        try{

            $user = User::where('email',$request->email)->first();

            if(empty($user)){

                $data['message'] = "User not found.";
                return response()->json(['status' => false, 'data' => $data]);

            }else{

                $verification_code = random_int(1000, 9999);
                $user->verification_code = $verification_code;
                $user->save();

                $details = [
                    'subject' => 'Reset Password Notification',
                    'heading' => 'Reset Password',
                    'text_one' => 'You are receiving this email because we received a password reset request for your account.',
                    'button_text' => $verification_code,
                    'text_two' => 'This password reset link will expire in 60 minutes',
                    'text_three' => 'If you did not request a password reset, no further action is required.'
                ];
        
                @Mail::to($request->email)->send(new \App\Mail\ValidateUser($details));

                $data['message'] = "Email sent successfully.";
                return response()->json(['status' => true, 'data' => $data]);

            }
            
            
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function VerifyForgetPasswordCode(Request $request){
        
        try{

            $userdata = $request->user();

            if(!empty($request->verification_code)){
                $user = User::where('verification_code',$request->verification_code)->first();

                if(!empty($user)){
                    if($user->verification_code == $request->verification_code){

                    $data['email'] = $user->email;
        
                        $data['message'] = "User Validated Successfully.";
                        return response()->json(['status' => true, 'data' => $data]);
        
                    }else{
                        $data['message'] = "Invalid Code.";
                        return response()->json(['status' => false, 'data' => $data]);
                    }
                }else{
                    $data['message'] = "Invalid Code.";
                    return response()->json(['status' => false, 'data' => $data]);
                }
            }else{
                $data['message'] = "Invalid Code.";
                return response()->json(['status' => false, 'data' => $data]); 
            }
            
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
        
    }

    public function ChangeUserPassword(Request $request){

        try{
        
        $user = User::where('email',$request->email)->first();

        $user->password         = app('hash')->make($request->password);
        $user->verification_code = "";

        if($user->save()){
            $data['message'] = 'Successfully reset password';
            return response()->json(['status' => true, 'data' => $data]);
        }else{
            $data['message'] = 'Something went wrong.';
            return response()->json(['status' => false, 'data' => $data]);
        }

        }catch(BadRequestException $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
        
    }
    
}
