<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class PassportAuthController extends Controller
{
    //

    public function register(Request $request){
        
        $fullname     = $request->fullname;
        $email          = $request->email;
        $username       = strtolower(preg_replace('/[^0-9a-zA-Z]/', '', $request->username));
        //$social_profile =  $request->social_profile;
        $password       = $request->password;
        $status         = "2";


        //Validate Email Address
        if(empty($fullname) OR empty($username)  OR empty($email) OR empty($password) ){
            return response()->json(['status' => false, 'message' => 'You must fill all the fields']);
        }

        //Validate Email Address
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            return response()->json(['status' => false, 'message' => 'You must fill all the fields']);
        }

        //Password Should be Greater Or Equal to 6 Characters
        if(strlen($password) < 6){
            return response()->json(['status' => false, 'message' => 'Password should be 6 characters ']);
        }

        //Check User Already Exists
        if(User::where('email','=',$email)->exists()){
            return response()->json(['status' => false, 'message' => 'Email already taken.']);
        }

        try{
            $verification_key = $this->getToken(16);
            
            $user = new User;
            
            $user->fullname = $fullname;
            $user->email = $email;
            $user->username = $username;
            //$user->social_profile = $social_profile;
            $user->password = app('hash')->make($password);
            $user->status = $status;
            $user->verification_key = $verification_key;

            //Save User
            if($user->save()){

                // $details = [
                //     'subject' => 'Verify your FashionVista Account',
                //     'heading' => 'E-mail Address Validation',
                //     'text_one' => 'Please validate your account:',
                //     'button_text' => 'Verify Now',
                //     'button_link' => env('WEB_URL').'validateuser/'.$verification_key
                // ];

                // @Mail::to($email)->cc([env('PROJECT_EMAIL')])->send(new \App\Mail\ValidateUser($details));

                return response()->json(['status' => true, 'message' => "Register succesfully"]);
                //return $this->login($request);
            }
           
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
        
    }

    public function login(Request $request){
        $email = $request->email;
        $password = $request->password;
        $device_token = $request->device_token;

        if(empty($email) OR empty($password) ){
            return response()->json(['status' => 'error', 'message' => 'You must fill all the fields']);
        }
        try{
            
            $data = [
                'email' => $email,
                'password' => $password
            ];
     
            if (auth()->attempt($data)) {
                $token = auth()->user()->createToken('flatmeta')->accessToken;
                $data['token'] = $token;

                return response()->json(['status' => true, 'data' => $data]);
            } else {
                return response()->json(['error' => 'Unauthorised'], 401);
            }
           
        }catch(BadRequestException $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }

    function getToken($length){
		$token = "";
		$codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
		$codeAlphabet.= "abcdefghijklmnopqrstuvwxyz";
		$codeAlphabet.= "0123456789";
		$max = strlen($codeAlphabet);

		for ($i=0; $i < $length; $i++) {
			$token .= $codeAlphabet[random_int(0, $max-1)];
		}

		return $token;
	}

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
    
}
