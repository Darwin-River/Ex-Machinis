<?php

namespace App\Http\Controllers;

use App\Agent;
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
use PhpImap\Mailbox;
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

    /*  public function test()
      {

          echo Mail::send(['email.welcome_html', 'email.welcome_text'], [], function ($message) {
              $message->to("puppeteer.fernando@gmail.com")->from("registrar@" . getenv("MAIL_HOST"))->subject('Testing email');
          });

      }*/

    /**
     * Download mails from the server and process them. Called by main loop.
     */
    public function getMails($key)
    {

        if ($key != env("CRON_KEY"))
            die("wrong key");

        // 4. argument is the directory into which attachments are to be saved:
        $mailbox = new Mailbox('{' . getenv('MAIL_HOST') . ':' . getenv('MAIL_INCOMING_PORT') . '/imap/' . getenv('MAIL_ENCRYPTION') . '}INBOX', getenv('MAIL_REGISTRATION_ACCOUNT'), getenv('MAIL_REGISTRATION_PASSWORD'), null);

// Read all messaged into an array:
        $mailsIds = $mailbox->searchMailbox('ALL');
        $mails = $mailbox->imap('fetch_overview', [implode(',', $mailsIds), FT_UID]);
        if (!$mails) {
            echo 'Mailbox registrar is empty<br/>';
        } else {

            foreach ($mailsIds as $mailId) {
                $mail = $mailbox->getMail($mailId);
                echo "received mail with subject <b>" . strtolower(trim($mail->subject)) . '</b><br/>';
                if (strtolower(trim($mail->subject)) == "register") {
                    //check if user exists
                    //  $user = User::where('email', strtolower($mail->fromAddress))->first();
                    User::registerUser(strtolower($mail->fromAddress), $mail->fromName);

                }
                $mailbox->deleteMail($mailId);
            }
        }
        //now check catch-all mails
        $mailbox = new Mailbox('{' . getenv('MAIL_HOST') . ':' . getenv('MAIL_INCOMING_PORT') . '/imap/' . getenv('MAIL_ENCRYPTION') . '}INBOX', getenv('MAIL_CATCHALL_ACCOUNT'), getenv('MAIL_CATCHALL_PASSWORD'), null);

// Read all messaged into an array:
        $mailsIds = $mailbox->searchMailbox('ALL');
        $mails = $mailbox->imap('fetch_overview', [implode(',', $mailsIds), FT_UID]);
        if (!$mails) {
            echo 'Mailbox catchall is empty<br/>';
        } else {
            foreach ($mailsIds as $mailId) {
                $mail = $mailbox->getMail($mailId);
                echo "received mail with subject <b>" . strtolower(trim($mail->subject)) . '</b><br/>';
                $recipients = $mail->to;
                foreach ($recipients as $address => $name) {
                    $mailAddressParts = explode('@', $address);
                    if (strtolower($mailAddressParts[1]) != getenv('MAIL_HOST'))
                        continue;//this address is from another domain
                    $localAddress = $mailAddressParts[0];

                    echo "received mail from <b>" . $localAddress . '</b><br/>';

                    //check if an agent of that name exists
                    $agent = Agent::where(['name' => $localAddress])->first();

                    if ($agent == null) {
                        //notify back
                        if (strtolower($mailAddressParts[1]) != getenv('MAIL_HOST'))//fix: avoid loop of notifying yourself
                            Mail::raw('Your message was not delivered to ' . $address . " because the address wasn't found or it can't receive mails.", function ($message) use ($mail) {
                                $message->to($mail->fromAddress, $mail->fromName)
                                    ->from("services@" . getenv("MAIL_HOST"), getenv("APP_NAME"))
                                    ->subject("[" . getenv("APP_NAME") . "] Email address not found");
                            });
                        continue;
                    }


                    $agent_user = User::where(['user_id' => $agent->user_id])->first();

                    echo "received mail for agent that belongs to<b>" . var_dump($agent_user) . '</b><br/>';

                    echo "Compare<b>" . $mail->fromAddress . ' with ' . $agent_user->email . '</b><br/>';

                    //check if user email corresponds with agent
                    if (trim(strtolower($mail->fromAddress)) != trim(strtolower($agent_user->email))) {
                        //notify back
                        Mail::raw("Your email address doesn't have access to the agent associated with " . $address . ". Please use the address you have registered with.", function ($message) use ($mail) {
                            $message->to($mail->fromAddress, $mail->fromName)
                                ->from("services@" . getenv("MAIL_HOST"), getenv("APP_NAME"))
                                ->subject("[" . getenv("APP_NAME") . "] Forbidden access");
                        });
                        continue;
                    }

                    // Get the email content as plain text
                    if ($mail->textPlain != null)
                        $mail_content = $mail->textPlain;
                    else
                        $mail_content = Html2Text::convert($mail->textHtml);


                    echo "Inserting command" . $mail_content . '</b><br/>';

                    // Extract code and add it to DB 
                    $codeAdded = false;
                    $codeAdded = $agent->insertCommandInfo($mail_content, $mail->subject);

                    if (!$codeAdded) {
                        echo 'Invalid content received<br/>';

                        $data = [];
                        $data['from'] = $mail->fromAddress;
                        foreach ($recipients as $address => $name) {
                            // Pick just the first
                            $data['to'] = $address;
                            break;
                        }
                        $data['subject'] = $mail->subject;
                        $data['sent'] = $mail->date;
                        $data['input_content'] = $mail_content;

                        echo Mail::send(['email.wrong_email_html', 'email.wrong_email_text'], $data, function ($message) use ($mail) {
                            $message->to($mail->fromAddress, $mail->fromName)->from($mail->to, getenv("APP_NAME"))->subject('Re: ' . $mail->subject);
                        });
                    }
                }
                $mailbox->deleteMail($mailId);
            }
        }

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
     */
    public function queryCommands()
    {
        $queryCommands = Query::orderBy('id', 'asc')->get();
        return view('query-commands', compact('queryCommands'));
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
            'resources.name as resource_name', 'events.locked', 'events.new_quantity', 'events.new_credits', 'events.new_location', 'objects.object_id', 'objects.object_name')
            ->leftJoin('observations', 'events.id', '=', 'observations.event')
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
        $query->where([['observations.drone', '=', 0], ['observations.time', '<=', Carbon::now()->toDateTimeString()]]);
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

        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        } else
            $query->orderBy('time', 'desc');
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


}
