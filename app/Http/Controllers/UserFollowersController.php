<?php

namespace App\Http\Controllers;

use DateTime;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\UserFollowers;

class UserFollowersController extends Controller
{
    //

    public function SendFriendRequest(Request $request){

        $userdata = $request->user();

        try{ 
            $follower_user_id = $request->follower_user_id;

            $followers = new UserFollowers();    
            $followers->user_id             = $userdata['id'];
            $followers->follower_user_id    = $follower_user_id;
            $followers->status              = "2";

            if($followers->save()){
                $data['message'] = "Friend request sent successfully.";
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                $data['message'] = "Something went wrong.";
                    return response()->json(['status' => false, 'data' => $data]);
            }

            

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function GetAllFriendRequest(Request $request){

        $userdata = $request->user();

        try{ 
            $friendrequests = UserFollowers::where('follower_user_id',$userdata['id'])->get();

            if(!empty($friendrequests)){
                foreach($friendrequests as $key => $friend){
                    $followerData = User::where('id', $friend->user_id)->firstOrFail();

                    if(!empty($followerData)){

                        $data['requests'][$key]['request_id'] = $friend->id;

                        if(!empty($followerData->image)){
                            $data['requests'][$key]['image'] = env('APP_URL').'assets/uploads/users/'.$followerData->image; 
                        }else{
                            $data['requests'][$key]['image'] = env('APP_URL').'assets/uploads/users/noimage.png'; 
                        }

                        $data['requests'][$key]['message'] = $followerData->username." sent you a friend request.";
                        $data['requests'][$key]['status'] = $friend->status;
                        $data['requests'][$key]['created_at']  	= $this->time_elapsed_string($friend->created_at); 
                        
                    }
                }
            }

            if(!empty($data)){
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                $data['requests'] = array();
                return response()->json(['status' => true, 'data' => $data]);
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function UpdateFriendRequestStatus(Request $request){

        $userdata = $request->user();

        try{ 

            $id = $request->request_id;

            $friendrequests = UserFollowers::where('id',$id)->first();
            $friendrequests->status = $request->status;

            if($friendrequests->status == "1"){
                $data['message'] = "Friend request accepted successfully";
            }else{
                $data['message'] = "Friend request declined successfully";
            }
            
            if($friendrequests->save()){
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                return response()->json(['status' => false, 'data' => $data]);
            }

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    function time_elapsed_string($datetime, $full = false) {
        $now = new DateTime;
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
    
        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;
    
        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }
    
        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }

    public function GetRoomId(Request $request){

        $userdata = $request->user();

        try{ 
            $friendrequests = UserFollowers::where('follower_user_id',$userdata['id'])->where('user_id',$request->follower_user_id)->first();

            if(empty($friendrequests)){

                $friendrequests = UserFollowers::where('user_id',$userdata['id'])->where('follower_user_id',$request->follower_user_id)->first();

                if($friendrequests['status'] == "1"){
                    $data['room_id'] = $friendrequests['id'];
                    return response()->json(['status' => true, 'data' => $data]);
                }
                
            }else{
                if($friendrequests['status'] == "1"){
                    $data['room_id'] = $friendrequests['id'];
                    return response()->json(['status' => true, 'data' => $data]);
                }
            }
           
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    
}
