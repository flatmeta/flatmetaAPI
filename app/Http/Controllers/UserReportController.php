<?php

namespace App\Http\Controllers;

use App\Models\UserReport;
use Illuminate\Http\Request;

class UserReportController extends Controller
{
    //

    public function AddUserReport(Request $request){
        
        try{

            $userdata = $request->user();

            $postreport = new UserReport();
            $postreport->user_id = $userdata->id;
            $postreport->reported_user_id = $request->user_id;

            if($request->report_id == "6"){
                $postreport->report_id = $request->report_id;
                $postreport->report_text = $request->report_text;
            }else{
                $postreport->report_id = $request->report_id;
            }
            
            $postreport->status = "1";

            if($postreport->save()){
                $data['message'] = "Thanks for sending us your report.";
                return response()->json(['status' => true, 'data' => $data]);
            }
           
        }catch(\Exception $e){
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

    }

    public function UserReports(Request $request){
        
        
        $data['ReportedUsers'] = UserReport::ReportedUser();
         
        
        return view('UserReport.list',$data);
       

    }
}
