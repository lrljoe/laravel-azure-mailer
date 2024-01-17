<?php

namespace Avantia\Azure\Mailer;

use Illuminate\Support\ServiceProvider;

class MailerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        \App::register(Providers\AzureMailerServiceProvider::class);
        \Event::subscribe(Listeners\EmailEventSubscriber::class);
    }
    
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
