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
        $fullname     = trim($request->fullname ?? '');
        $email        = trim($request->email ?? '');
        $username     = strtolower(preg_replace('/[^0-9a-zA-Z_]/', '', $request->username ?? ''));
        $password     = $request->password;
        $status       = "1"; // Active

        // Validate fields
        if(empty($fullname) || empty($username) || empty($email) || empty($password)){
            return response()->json(['status' => false, 'message' => 'You must fill all the fields']);
        }

        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            return response()->json(['status' => false, 'message' => 'Please enter a valid email address']);
        }

        if(strlen($password) < 6){
            return response()->json(['status' => false, 'message' => 'Password should be at least 6 characters']);
        }

        if(User::where('email', $email)->exists()){
            return response()->json(['status' => false, 'message' => 'Email already registered.']);
        }

        if(User::where('username', $username)->exists()){
            return response()->json(['status' => false, 'message' => 'Username already taken.']);
        }

        try{
            $verification_key = $this->getToken(16);
            
            $user = new User;
            $user->fullname = $fullname;
            $user->email = $email;
            $user->username = $username;
            $user->password = Hash::make($password);
            $user->status = $status;
            $user->verification_key = $verification_key;

            if($user->save()){
                return response()->json(['status' => true, 'message' => "Registered successfully. Please login!"]);
            }

            return response()->json(['status' => false, 'message' => 'Unable to save user']);
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function login(Request $request){
        $login = trim($request->email ?? $request->username ?? '');
        $password = $request->password;

        if(empty($login) || empty($password)){
            return response()->json(['status' => false, 'message' => 'Please enter both your email/username and password']);
        }

        try{
            $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
            $credentials = [
                $field => $login,
                'password' => $password
            ];
     
            if (auth()->attempt($credentials)) {
                $user = auth()->user();
                $token = $user->createToken('flatmeta')->accessToken;
                return response()->json([
                    'status' => true, 
                    'access_token' => $token,
                    'data' => [
                        'user_id' => $user->id,
                        'fullname' => $user->fullname,
                        'username' => $user->username,
                        'email' => $user->email,
                        'status' => $user->status
                    ]
                ]);
            } else {
                $returndata['message'] = "Wrong Email or Password";
                return response()->json(['status' => false, 'data' => $returndata]);
            }
        }catch(\Exception $e){
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
