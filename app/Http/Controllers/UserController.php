<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{

    /**
     * Sends a mail with a list of all current users
     * @param int $key validation key
     */
    public function reportCurrentUsersByMail($key)
    {

        $currentUsers = User::with('agents')
            ->orderBy('agent_id', 'desc')
            ->get();
        try {
            Mail::send('email.users_report', ['currentUsers' => $currentUsers], function ($message)/* use ($user, $mail)*/ {
                $message->to(getenv('REPORTS_DESTINATION'))->from("notifitacions@" . getenv("MAIL_HOST"), getenv("APP_NAME"))->subject("[" . getenv("APP_NAME") . "] Users Report");
            });

            echo "Notification sent";
        } catch (\Exception $e) {
            echo "There was an error:" . $e->getMessage();
        }
    }

    /**
     * Creates user and shipcraft from submitted form
     * @param $request Request object
     */
    public function webRegistration(Request $request)
    {
        $name = $request->post('name');
        $email = $request->post('email');
        User::registerUser($email, $name);
    }

}
