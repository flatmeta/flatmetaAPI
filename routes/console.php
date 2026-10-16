<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('flatmeta:make-admin {email} {--revoke}', function ($email) {
    $user = \App\Models\User::where('email', strtolower(trim($email)))->first();
    if (!$user) {
        $this->error("No user with email {$email}");
        return 1;
    }
    $user->is_admin = !$this->option('revoke');
    $user->save();
    $this->info(($user->is_admin ? 'Granted' : 'Revoked') . " CMS access for {$user->email}");
})->purpose('Grant (or --revoke) CMS admin access for a user');
