<?php

namespace App\Http\Controllers;

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
        
        try{

            $img_url = $request->image_url;
            $save_to = 'assets/uploads/users/image.svg';
            $this->save_image($img_url, $save_to);

           
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }


}
