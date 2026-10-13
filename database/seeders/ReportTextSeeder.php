<?php

namespace Database\Seeders;

use App\Models\ReportText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReportTextSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        DB::table('report_texts')->delete();

        $texts = array(
            [ 'text' => "Spam account",'status' => "1"],
            [ 'text' => "Nudity, pornography, or violence",'status' => "1"],
            [ 'text' => "Pretending to be someone else",'status' => "1"],
            [ 'text' => "Inappropriate comments",'status' => "1"],
            [ 'text' => "Harassment or bullying",'status' => "1"],
            [ 'text' => "Other",'status' => "1"],
        );

        foreach($texts as $text){
            ReportText::create($text);
        }
    }
}
