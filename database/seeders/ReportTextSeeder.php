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
            [ 'name' => "Spam account",'status' => "1"],
            [ 'name' => "Nudity, pornograpphy, or violence",'status' => "1"],
            [ 'name' => "Pretending to be someone else",'status' => "1"],
            [ 'name' => "Inappropriate comments",'status' => "1"],
            [ 'name' => "Harassment or bullying",'status' => "1"],
            [ 'name' => "Other",'status' => "1"],
        );

        foreach($texts as $text){
            ReportText::create($text);
        }
    }
}
