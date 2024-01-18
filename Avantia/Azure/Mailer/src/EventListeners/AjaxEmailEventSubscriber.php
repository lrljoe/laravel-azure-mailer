<?php

/*
 * This file is part of the Avantia package.
 *
 * (c) Juan Luis Iglesias <jliglesias@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Avantia\Azure\Mailer\EventListeners;

use Avantia\Azure\Mailer\Events\AjaxSendEmailNotificationEvent;

use Illuminate\Http\JsonResponse;
use Symfony\Component\Mime\Address;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\HttpClient\HttpClient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Events\Dispatcher;

class AjaxEmailEventSubscriber
{
    
    private function getEmailclass(AjaxSendEmailNotificationEvent $event): object {

        if (empty(((object) $event->request->email)->class)){
            $email = (object) $event->request->email;
            $email->class = new \stdclass(); $email->class->name = 'Avantia\Azure\Mailer\Mailables\DefaultEmail'; $email->class->args = [];
        }else $email = (object) $event->request->email; $email->class = (object) $email->class;
        
        $email->class->name = (empty($email->class->name)) ? 'Avantia\Azure\Mailer\Mailables\DefaultEmail': $email->class->name;
        $email->class->args = (empty($email->class->args)) ? []: $email->class->args;

        return $email;
    }

    private function getRecients(AjaxSendEmailNotificationEvent $event, $type): Array {

        $recipients = [];
        if (empty(((object) $event->request->email)->$type)){
            if(strtolower($type) === "to") $recipients[] =  new Address(Auth::user()->email, Auth::user()->getFullName());
        }else {
            $email = (object) $event->request->email;
            foreach($email->$type as $recipient){
                if (!empty($recipient["email"])) $recipients[] = new Address($recipient["email"], (empty($recipient["name"])) ? '':$recipient["name"]);
            }
        }
        return $recipients;
    }

    private function getClass(object $email): object{

        $reflect  = new \ReflectionClass($email->class->name);
        $instance = (empty($email->class->args)) ? new $email->class->name : $reflect->newInstanceArgs($email->class->args);

        return $instance;
    }

    /**
     * Handle user login events.
     */
    public function SendMail(AjaxSendEmailNotificationEvent $event): JsonResponse {
       
       // Log::debug('EmailEventSubscriber->SendMail');
        if (empty($event->request->email)) {
            $res = response()->json(
                [
                    "statusCode" => 500,
                    "error" => sprintf ("jsonParseError: Undefined \"%s\" json object","email"),
                ]
            );
            return $res;
        }
        $email = $this->getEmailclass($event);
        if(class_exists($email->class->name)){
            Mail::to($this->getRecients($event, 'to'))
                  ->cc($this->getRecients($event, 'cc'))
                  ->bcc($this->getRecients($event, 'bcc'))
                  ->send($this->getClass($email));
            $res = response()->json( 
                [ 
                    "statusCode" => 202,
                    "data" => []
                ] );
        }else{
            $res = response()->json(
                [
                    "statusCode" => 500,
                    "error" => sprintf (" Undefined \"%s\" email class template",$email->class->name),
                ]
            );
        }

        return $res;
    }
 
    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array{
        return [
            AjaxSendEmailNotificationEvent::class => 'SendMail',
        ];
    }

}