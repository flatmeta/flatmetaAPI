<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index($type = null, $date = null)
    {

        if($type == 'custom'){

            $newdates = explode(' to ',$date);  

            $startdate=date_create($newdates[0]);
            $enddate=date_create($newdates[1]);

            ${$type.'start'} = date_format($startdate,"Y-m-d");
            ${$type.'end'} = date_format($enddate,'Y-m-d');
            $data['type'] = "custom";
            $data['selecteddates'] = $date;
            $data['startdate'] = $startdate;
            $data['enddate'] = $enddate;

        }else{

            // Starting today
            $todaystart = date('Y-m-d 00:00:00', time());
            // It’s over today.
            $todayend = date('Y-m-d 23:59:59', time());

            // It started yesterday.
            $yesterdaystart = date('Y-m-d 00:00:00', strtotime(' -1 day'));
            // It ended yesterday.
            $yesterdayend = date('Y-m-d 23:59:59', strtotime('-1 day'));

            // This week begins, Monday begins.
            $this_weekstart = date('Y-m-d 00:00:00', strtotime('this week monday'));
            // This week ends, Sunday ends.
            $this_weekend = date('Y-m-d 23:59:59', strtotime('this week sunday'));

            // Starting last week, Monday
            $last_weekstart = date('Y-m-d 00:00:00', strtotime('last week monday'));
            // Last week ended, Sunday ended.
            $last_weekend = date('Y-m-d 23:59:59', strtotime('last week sunday'));

            // Beginning this month
            $this_monthstart = date('Y-m-01 00:00:00');
            // End of the month
            $this_monthend = date('Y-m-d 23:59:59', strtotime('Last day of this month'));

            // Beginning last month
            $last_monthstart = date('Y-m-01 00:00:00', strtotime('last month'));
            // End of last month
            $last_monthend = date('Y-m-d 23:59:59', strtotime('Last day of last month'));

            // Beginning of this quarter
            //$this_quarterstart = date('Y-m-01 00:00:00', strtotime((1 – (date('n') % 3 == 0 ? 3 : date('n') % 3)) . ' month'));
            // The end of the quarter
            //$this_quarterend = date('Y-m-d 23:59:59', strtotime('last day of' . (3 – (date('n') % 3 == 0 ? 3 : date('n') % 3)) . ' month'));

            // Beginning of last quarter
            //$last_quarterstart = date('Y-m-01 00:00:00', strtotime((-2 – (date('n') % 3 == 0 ? 3 : date(‘n’) % 3)) . ' month'));
            // Last quarter ended
            //$last_quarterend = date('Y-m-d 23:59:59', strtotime('last day of' . (- (date('n') % 3 == 0 ? 3 : date('n') % 3)) . ' month'));

            // Beginning of the year
            $this_yearstart = date('Y-01-01 00:00:00');
            // End of the year
            $this_yearend = date('Y-12-31 23:59:59');

            // Beginning of last year
            $last_yearstart = date('Y-01-01 00:00:00', strtotime('last year'));
            // End of last year
            $last_yearend = date('Y-12-31 23:59:59', strtotime('last year'));

            if(empty($type)){
                ${$type.'start'} = $todaystart;
                ${$type.'end'} = $todayend;
                $data['type'] = "today";
            }else{
                $data['type'] = $type;
            }
        }

        $data['Users'] = User::count();
        $data['Tiles'] = Orders::GetTilesCount();
        $data['type'] = "";


        dd($data['Tiles']);

        return view('dashboard',$data);
    }

    public function logout() {
        Auth::logout();
        return redirect('/login');
    }
}
