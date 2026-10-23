<?php

namespace App\Http\Controllers;

use App\Agent;
use App\CommandTooLongException;
use App\Company;
use App\Query;
use App\Resource;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use App\Services\Mail\Inbox;
use App\Services\Mail\IncomingMail;
use App\Services\Mail\InboxFactory;
use Html2Text\Html2Text;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //  $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('home');
    }

    /**
     * Download mails from the server and process them. Called by the cron
     * email handler (game-engine/scripts/start_email_handler.sh).
     *
     * - registrar inbox: a mail with subject "register" (or "magica") registers the sender.
     * - catch-all inbox: a mail to <drone name>@MAIL_DOMAIN from the drone
     *   owner's address is queued as commands for that drone.
     * Every processed mail is deleted.
     */
    public function getMails($key, InboxFactory $inboxes)
    {
        $this->requireCronKey($key);

        $log = [];

        $inbox = $inboxes->open(getenv('MAIL_REGISTRATION_ACCOUNT'), getenv('MAIL_REGISTRATION_PASSWORD'));
        $mails = $inbox->messages();
        if (!$mails) {
            $log[] = 'Mailbox registrar is empty';
        }
        foreach ($mails as $mail) {
            $log[] = 'received mail with subject <b>' . e(strtolower(trim($mail->subject))) . '</b>';
            $this->processMail($inbox, $mail, $log, function () use ($mail) {
                // "register" is what the player's manual tells players to use
                if (in_array(strtolower(trim($mail->subject)), ['register', 'magica'], true)) {
                    $name = $mail->fromName;
                    if (!$name) {
                        $name = explode('@', $mail->fromAddress)[0];
                    }
                    // Use the fallback name (before, the sender's possibly empty
                    // display name was passed, and registration then failed).
                    User::registerUser(strtolower($mail->fromAddress), $name);
                }
            });
        }

        // Now check catch-all mails
        $inbox = $inboxes->open(getenv('MAIL_CATCHALL_ACCOUNT'), getenv('MAIL_CATCHALL_PASSWORD'));
        $mails = $inbox->messages();
        if (!$mails) {
            $log[] = 'Mailbox catchall is empty';
        }
        foreach ($mails as $mail) {
            $log[] = 'received mail with subject <b>' . e(strtolower(trim($mail->subject))) . '</b>';
            $this->processMail($inbox, $mail, $log, function () use ($mail, &$log) {
                foreach ($mail->to as $address => $name) {
                    $this->processCommandMail($mail, $address, $log);
                }
            });
        }

        return response(implode("<br/>\n", $log) . "<br/>\n");
    }

    /**
     * Run $handle for one mail, then delete the mail. If it fails, log the
     * error and keep the mail for the next run, but carry on with the other
     * mails: one bad mail must not hold up everyone else's.
     */
    private function processMail(Inbox $inbox, IncomingMail $mail, array &$log, \Closure $handle): void
    {
        try {
            $handle();
        } catch (\Throwable $e) {
            report($e);
            $log[] = 'error processing mail from <b>' . e($mail->fromAddress) . '</b>, kept for retry';
            return;
        }
        $inbox->delete($mail);
    }

    /**
     * Handle one recipient of a catch-all mail: queue its commands for the
     * drone named by the address, if the sender owns that drone.
     */
    private function processCommandMail(IncomingMail $mail, string $address, array &$log): void
    {
        $mailAddressParts = explode('@', $address);
        if (count($mailAddressParts) != 2 || strtolower($mailAddressParts[1]) != strtolower(config('mail.domain'))) {
            return; // this address is for another domain
        }
        $localAddress = $mailAddressParts[0];
        $log[] = 'received mail for <b>' . e($localAddress) . '</b>';

        $agent = Agent::where(['name' => $localAddress])->first();
        if ($agent == null) {
            // No "address not found" reply: the old code skipped it too, and
            // replying to catch-all mail would mostly answer spam.
            return;
        }

        $agent_user = User::where(['user_id' => $agent->user_id])->first();

        // The sender must be the drone owner's registered address
        if (trim(strtolower($mail->fromAddress)) != trim(strtolower($agent_user->email))) {
            Mail::raw("Your email address doesn't have access to the agent associated with " . $address . ". Please use the address you have registered with.", function ($message) use ($mail) {
                $message->to($mail->fromAddress, $mail->fromName)
                    ->from("services@" . config('mail.domain'), getenv("APP_NAME"))
                    ->subject("[" . getenv("APP_NAME") . "] Forbidden access");
            });
            return;
        }

        // Get the email content as plain text
        $mail_content = $mail->textPlain !== null ? $mail->textPlain : Html2Text::convert((string) $mail->textHtml);

        $log[] = 'Inserting command ' . e($mail_content);
        try {
            if ($agent->insertCommandInfo($mail_content, $mail->subject)) {
                return;
            }
        } catch (CommandTooLongException $e) {
            $log[] = 'Command too long';
            Mail::raw("Your program for " . $address . " is " . $e->length . " characters long; the limit is " . $e->max . ". Split it across several emails: words you define stay defined in the drone.", function ($message) use ($mail, $address) {
                $message->to($mail->fromAddress, $mail->fromName)
                    ->from($address, getenv("APP_NAME"))
                    ->subject('Re: ' . $mail->subject);
            });
            return;
        }

        $log[] = 'Invalid content received';
        $data = [
            'from' => $mail->fromAddress,
            'to' => $address,
            'subject' => $mail->subject,
            'sent' => $mail->date,
            'input_content' => $mail_content,
        ];
        Mail::send(['email.wrong_email_html', 'email.wrong_email_text'], $data, function ($message) use ($mail, $address) {
            // Reply from the drone's own address
            $message->to($mail->fromAddress, $mail->fromName)->from($address, getenv("APP_NAME"))->subject('Re: ' . $mail->subject);
        });
    }

    /**
     * Display static page with forth dictionary
     */
    public function forthDictionary()
    {
        return view('pages.forth-dictionary');
    }

    /**
     * Display static page with player's manual
     */
    public function playersManual()
    {
        return view('pages.players-manual');
    }

    /**
     * Displays query commands table
     * Request $request
     */
    public function queryCommands(Request $request)
    {
        $queryCommands = Query::orderBy('id', 'asc')->get();
        $keyword = $request->get('keyword');
        return view('query-commands', compact('queryCommands', 'keyword'));
    }

    /**
     * Displays the in-game events table
     * @return View
     */
    public function inGameEvents()
    {
        return view('in-game-events');
    }

    /**
     * Used by search engine to retrieve in-game events
     * @param $request Request object
     * @return JsonResponse
     */
    public function InGameEventsSearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');
        $query = DB::table('events')->select('events.timestamp', 'acting_agents.name as acting_agent_name', 'acting_agents.user_id as acting_company_id', 'acting_users.name as acting_company_name',
            'acting_agents.agent_id as acting_agent_id', 'acting_agents.name as acting_agent_name', 'protocols.name as protocol_name', 'affected_users.name as affected_company_name', 'affected_users.user_id as affected_company_id',
            'event_types.name as event_type_name', 'affected_agents.agent_id as affected_agent_id', 'affected_agents.name as affected_agent_name', 'resources.id as resource_id',
            'resources.name as resource_name', 'events.locked', 'events.new_quantity', 'events.new_credits', 'events.new_location', 'objects.object_id', 'objects.object_name', 'observations.drone as observing_drone')
            ->join('observations', 'events.id', '=', 'observations.event') //obligatory to be observed
            ->leftJoin('actions', 'events.action', '=', 'actions.id')
            ->leftJoin('agents as acting_agents', 'actions.drone', '=', 'acting_agents.agent_id')
            ->leftJoin('users as acting_users', 'acting_agents.user_id', '=', 'acting_users.user_id')
            ->leftJoin('protocols', 'actions.protocol', '=', 'protocols.id')
            ->leftJoin('event_types', 'events.event_type', '=', 'event_types.id')
            ->leftJoin('agents as affected_agents', 'events.drone', '=', 'affected_agents.agent_id')
            ->leftJoin('users as affected_users', 'affected_agents.user_id', '=', 'affected_users.user_id')
            ->leftJoin('resources', 'events.resource', '=', 'resources.id')
            ->leftJoin('objects', 'events.new_location', '=', 'objects.object_id');
        //basic restrictions
        $query->where([/*['observations.drone', '=', 0],*/ ['observations.time', '<=', Carbon::now()->toDateTimeString()]]);
        if ($request->get('keyword') !== null)
            $query->where(function ($query) use ($request) {
                $query->where('affected_agents.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('acting_agents.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('protocols.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('affected_users.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('resources.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('event_types.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('object_name', 'LIKE', "%" . $request->get('keyword') . "%");
            });

        if ($request->get('observing_agent') !== null) {
            $query->where('observations.drone', '=', $request->get('observing_agent'));
        } else
            $query->where('observations.drone', '=', 0);

        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else
            $query->orderBy('time', 'desc');
        if ($request->get('limit')) {
            // $query->take($request->get('limit'));
            $inGameEvents = $query->take(10)->get();
            $inGameEvents = ['current_page' => 1, 'data' => $inGameEvents, 'per_page' => 10, 'total' => sizeof($inGameEvents), 'to' => sizeof($inGameEvents), 'preve_page_url' => null, 'from' => 1];
        } else
            $inGameEvents = $query->paginate($resultsPerPage);
        // var_dump($spaceObjects);exit;

        return response()->json($inGameEvents, 200);
    }

    /**
     * Displays the About Us information page
     * @return View
     */
    public function aboutUs()
    {
        return view('pages.about-us');
    }

    /**
     * Displays a flash message alone
     * @param $request Request object
     * @return \Illuminate\Http\Response
     */
    public function message(Request $request)
    {
        $this->querystringToFlash($request);
        return view('show_message');
    }


}
