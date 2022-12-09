<?php

namespace App\Http\Controllers;

use App\Models\ReportText;
use Illuminate\Http\Request;

class ReportTextController extends Controller
{
    //

    public function GetAllReportText(){
        
        try{
            
            $texts = ReportText::all()->where('status','1');
           
            foreach($texts as $key => $text){
                $data['reports'][$key]['id']     = $text->id;
                $data['reports'][$key]['text']   = $text->text;
            }

            return response()->json(['status' => true, 'data' => $data]);
            // return $this->login($request);
           
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }
}
