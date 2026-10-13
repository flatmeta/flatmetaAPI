<?php

namespace App\Http\Controllers;

use App\Models\ReportText;
use Illuminate\Http\Request;

class ReportTextController extends Controller
{
    //

    public function GetAllReportText(){
        try{
            $data = ['reports' => []];
            $texts = ReportText::where('status','1')->get();
           
            foreach($texts as $text){
                $data['reports'][] = [
                    'id' => $text->id,
                    'text' => $text->text
                ];
            }

            return response()->json(['status' => true, 'data' => $data]);
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function ReportText(Request $request){
        
        $data['texts'] = ReportText::all();
        return view('ReportText.list',$data);
    }

    public function CreateReportText($id = null){

        if(!empty($id)){
            $data['text'] = ReportText::where('id',$id)->first();
        }else{
            $data['text'] = "";
            
        }
        
        return view('ReportText.create',$data);
    }

    public function StoreReportText(Request $request){

        if(empty($request->id)){
            $request->validate([
                'text'          => 'required',
                'status'        => 'required',
            ]);

            $reportText = new ReportText();
            $reportText->text    = $request->text;
            $reportText->status  = $request->status;

            if($reportText->save()){
                return redirect()->route('ReportText');
            }
        }else{
            $request->validate([
                'text'          => 'required',
                'status'        => 'required',
            ]);

            $reportText = ReportText::findOrFail($request->id);
            $reportText->text    = $request->text;
            $reportText->status  = $request->status;

            if($reportText->save()){
                return redirect()->route('ReportText');
            }
        }
    }

    public function DeleteReportText(Request $request,$id){

        $reportText = ReportText::where('id', $id)->first();
        if ($reportText) {
            $reportText->status = "3";
            $reportText->save();
        }

        return redirect()->route('ReportText');
    }
}
