<?php

/*
 * This file is part of the Avantia package.
 *
 * (c) Juan Luis Iglesias <jliglesas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Avantia\Azure\Mailer\Http\Controllers;

use App\Http\Controllers\Controller;
use Avantia\Azure\Mailer\Events\SendEmailNotificationEvent as EmailNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Avantia\Azure\Mailer\Mail\DefaultEmail;
use Illuminate\Support\Facades\Mail;

class AjaxMailController extends Controller
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

    public function sendMail(Request $request):JsonResponse {

        if(!$request->ajax()) abort(404,$request->path() . ' page not found');
        $data = json_encode((EmailNotification::dispatch($request))[0]->original);
        return  response()->json([
                'data' => $data
        ]);

    }

}
