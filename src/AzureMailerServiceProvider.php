<?php

namespace Avantia\Azure\Mailer;

Use Avantia\Azure\Mailer\AzureMailerTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AzureMailerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Mail::extend('azure', function(){
            return new AzureMailerTransport();
        });

    }
}
