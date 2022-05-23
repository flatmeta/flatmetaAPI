<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserFollowers;
use Illuminate\Http\Request;
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

            $userdata = $request->user();

            $users = User::all();

            foreach($users as $key => $user){

                if(!empty($userdata)){
                    $friends = UserFollowers::where('user_id',$userdata['id'])->where('follower_user_id',$user->id)->first();

                    if(empty($friends)){
                        $friends = UserFollowers::where('user_id',$user->id)->where('follower_user_id',$userdata['id'])->first();
                    }
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
}
