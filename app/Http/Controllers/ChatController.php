<?php

namespace App\Http\Controllers;

use DateTime;
use App\Models\Chat;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    //

    public function AddNewMessaage(Request $request){

        try{ 

            //Validate Fields
            if(empty($request->room_id) OR empty($request->sender_id)  OR empty($request->message)){
                return response()->json(['status' => false, 'message' => 'You must fill all the fields']);
            }
            
            $Chat = new Chat();    
            $Chat->room_id          = $request->room_id;
            $Chat->sender_id        = $request->sender_id;
            $Chat->message          = $request->message;
           
            if($Chat->save()){
                $data['message']  = "Message added successfully.";
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
