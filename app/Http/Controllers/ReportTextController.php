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

    public function ReportText(Request $request){
        
        $data['texts'] = ReportText::all();
        return view('ReportText.list',$data);
    }

    public function CreateReportText($id = null){

        if(!empty($id)){
            $data['user'] = ReportText::where('id',$id)->first();
        }else{
            $data['user'] = "";
            
        }
        
        return view('Users.create',$data);
    }

    public function StoreReportText(request $request){

        if(empty($request->id)){

            $request->validate([
                'fullname'      => 'required',
                'username'      => 'required',
                'email'         => 'required|unique:users',
                'password'      => 'required',
                'status'        => 'required',
            ]);

            $User = new ReportText();
           
            $User->fullname       = $request->fullname;
            $User->username       = $request->username;
            $User->email          = $request->email;
            $User->password       = app('hash')->make($request->password);
            $User->status         = $request->status;

            if($User->save()){
                return redirect()->route('Users');
            }
        }else{
            $request->validate([
                'fullname'      => 'required',
                'username'      => 'required',
                'status'        => 'required',
            ]);

            $User = ReportText::findorFail($request->id);
            $User->fullname       = $request->fullname;
            $User->username       = $request->username;
            $User->email          = $request->email;
            if(!empty($request->password)){
                $User->password   = app('hash')->make($request->password);
            }            
            $User->status         = $request->status;
            
            if($User->save()){
                return redirect()->route('Users');
            }
        }
    }

    public function DeleteReportText(Request $request,$id){

        $User = ReportText::where('id', $id)->first();
        $User->status = "3";
        $User->save();

        if($User->save()){
            return redirect()->route('Users');
        }
    }
}
