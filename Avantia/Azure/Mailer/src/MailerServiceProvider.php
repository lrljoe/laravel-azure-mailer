<?php

/*
 * This file is part of the Avantia package.
 *
 * (c) Juan Luis Iglesias <jliglesas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
        \Event::subscribe(EventListeners\AjaxEmailEventSubscriber::class);

        \Route::group(['middleware' => ['web', 'auth']] , function(){
            \Route::get('/send-mail', [Http\Controllers\AjaxMailController::class, 'sendMail']);
        });
    }
    
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Resources/Views','mailer');
    }
}
