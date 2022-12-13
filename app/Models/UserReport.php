<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserReport extends Model
{
    use HasFactory;

    public static function ReportedUser()
    {
        return self::select('user_reports.*','users.user_fullname','reported_user.reported_user_fullname','report_texts.text')
        ->leftJoin('users','users.id','=','user_reports.user_id')
        ->leftJoin('users as reported_user','reported_user.id','=','user_reports.reported_user_id')
        ->leftJoin('report_texts','report_texts.id','=','user_reports.report_id')
        ->get();
    }


}
