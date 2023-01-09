<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    function save_image($img_url, $save_to){
        $ch = curl_init($img_url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER,1);
        $rawdata=curl_exec ($ch);
        curl_close ($ch);
        if(file_exists($save_to)){
            unlink($save_to);
        }
        $fp = fopen($save_to,'x');
        fwrite($fp, $rawdata);
        fclose($fp);
    }

    public function SaveImageFromUrl(Request $request){

        $userdata = $request->user();
        
        try{

            $img_url = $request->image_url;
            $path = 'assets/uploads/users/';
            $keyword = date('dmyhis');
            $save_to = $path.$keyword;
            $this->save_image($img_url, $save_to);

            $userdetails = User::where('id',$userdata['id'])->first();
            $userdetails->image = $keyword;
            $userdetails->save();

            $data['message'] = 'Image Save Successfully';
            $data['image_url'] = env('APP_URL').$save_to;

            return response()->json(['status' => false, 'data' => $data]);

           
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }


}
