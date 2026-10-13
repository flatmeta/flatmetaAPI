<?php

namespace App\Http\Controllers;

use DateTime;
use App\Models\Chat;
use App\Models\UserFollowers;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    //

    public function AddNewMessaage(Request $request){

        try{ 

            $room_id    = $request->roomId ?? $request->room_id;
            $sender_id  = $request->senderId ?? $request->sender_id;
            $message    = $request->message ?? $request->text;
            $created_at = $request->createdAt ?? $request->created_at ?? now();

            // Validate Fields
            if(empty($room_id) || empty($sender_id) || empty($message)){
                return response()->json(['status' => false, 'message' => 'You must fill all the fields']);
            }

            // Resolve conversation room if passed as string format "room_X"
            if (!is_numeric($room_id) && preg_match('/^room_(\d+)$/', $room_id, $m)) {
                $otherUserId = (int) $m[1];
                if (is_numeric($sender_id)) {
                    $myId = (int) $sender_id;
                    $conv = UserFollowers::where(function ($q) use ($myId, $otherUserId) {
                        $q->where('user_id', $myId)->where('follower_user_id', $otherUserId);
                    })->orWhere(function ($q) use ($myId, $otherUserId) {
                        $q->where('user_id', $otherUserId)->where('follower_user_id', $myId);
                    })->first();

                    if (!$conv) {
                        try {
                            $conv = UserFollowers::create([
                                'user_id' => $myId,
                                'follower_user_id' => $otherUserId,
                                'status' => '1',
                            ]);
                        } catch (\Throwable $t) {}
                    }
                    if ($conv) {
                        $room_id = $conv->id;
                    }
                }
            }
            
            $Chat = new Chat();    
            $Chat->room_id          = $room_id;
            $Chat->sender_id        = $sender_id;
            $Chat->message          = $message;
            $Chat->created_at       = $created_at;
           
            if($Chat->save()){
                $data['message']  = "Message added successfully.";
                $data['room_id']  = $room_id;
                return response()->json(['status' => true, 'data' => $data]);
            }else{
                $data['message']  = "Something Went Wrong.";
                return response()->json(['status' => false, 'data' => $data]);
            };

        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function GetAllMessagesByRoomId(Request $request,$id){

        try{ 

            $messages = Chat::where('room_id',$id)->get();
            
            if(empty($messages)){
                $data['messages'] = array();

                return response()->json(['status' => true, 'data' => $data]);
            }else{
                foreach($messages as $key => $message){
                    $data['messages'][$key]['id']           = $message->id;
                    $data['messages'][$key]['room_id']      = $message->room_id;
                    $data['messages'][$key]['sender_id']    = $message->sender_id;
                    $data['messages'][$key]['message']      = $message->message;
                    $data['messages'][$key]['created_at']   = $this->time_elapsed_string($message->created_at); ;
                }

                return response()->json(['status' => true, 'data' => $data]);
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
}
