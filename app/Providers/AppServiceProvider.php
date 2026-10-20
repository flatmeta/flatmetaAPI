<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // The CMS uses Bootstrap 5 (Falcon theme), so page links use Bootstrap markup.
        \Illuminate\Pagination\Paginator::useBootstrapFive();

        // Production runs behind HTTPS: make every generated link (PayPal return pages, uploads) https.
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
