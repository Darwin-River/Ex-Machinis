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
use Illuminate\Support\Facades\Cache;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

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
        // What's happening in the world (#324 step 17)
        $feed = \App\Services\World::feed();
        return view('home', compact('feed'));
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

        // The scheduler calls this every few seconds: the leaderboard's
        // hourly wealth snapshot rides along (#324 step 18)
        \App\Services\Leaderboard::snapshotIfDue();

        // Email switched off (#324 step 6): no mail is read
        if (!config('forge.email')) {
            return response('Email is switched off');
        }

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
            $this->replyUnknownDrone($mail, $address);
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

        $mail_content = self::commandText($mail);

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
     * The mail's text for the drone. Plain text, unless it holds no
     * meta-command and the HTML part does: some mail clients send an HTML
     * part with a plain part that lacks what the player typed.
     */
    public static function commandText(IncomingMail $mail): string
    {
        $hasCommand = fn (?string $text) => $text !== null && preg_match('/<(run|abort|reset|rename|rebrand)>/i', $text) === 1;

        if ($hasCommand($mail->textPlain) || $mail->textHtml === null) {
            return (string) $mail->textPlain;
        }

        $fromHtml = Html2Text::convert($mail->textHtml);
        return ($hasCommand($fromHtml) || $mail->textPlain === null) ? $fromHtml : $mail->textPlain;
    }

    /**
     * Mail to a drone name that doesn't exist (a typo, or a drone that has
     * been renamed). A registered player is told their drones' addresses;
     * anyone else gets no reply, so spam to the catch-all isn't answered.
     */
    private function replyUnknownDrone(IncomingMail $mail, string $address): void
    {
        $user = User::where('email', strtolower(trim($mail->fromAddress)))->first();
        if ($user == null) {
            return;
        }

        $domain = config('mail.domain');
        $drones = Agent::where('user_id', $user->user_id)->orderBy('agent_id')->pluck('name')
            ->map(fn ($name) => $name . '@' . $domain)->implode("\n");

        Mail::raw("There is no drone called " . $address . ". It may have been renamed.\n\nYour drones are:\n" . $drones . "\n", function ($message) use ($mail) {
            $message->to($mail->fromAddress, $mail->fromName)
                ->from("services@" . config('mail.domain'), getenv("APP_NAME"))
                ->subject('Re: ' . $mail->subject);
        });
    }

    /**
     * Display static page with forth dictionary
     */
    public function forthDictionary()
    {
        return view('pages.forth-dictionary');
    }

    /** Where the players' manual and its images live */
    private const PLAYERS_MANUAL = 'documents/players-manual.md';
    private const MANUAL_IMAGES = 'documents/images';
    private const REPOSITORY_DOCS = 'https://github.com/Darwin-River/Ex-Machinis/blob/main/documents/';

    /**
     * Getting Started: the players' manual (documents/players-manual.md),
     * rendered from the Markdown so the page never drifts from it
     */
    public function playersManual()
    {
        $manual = Cache::rememberForever('players-manual:' . filemtime(base_path(self::PLAYERS_MANUAL)),
            fn () => self::renderPlayersManual(file_get_contents(base_path(self::PLAYERS_MANUAL))));

        return view('pages.players-manual', compact('manual'));
    }

    /** The manual as HTML, with GitHub-style heading IDs for its links */
    public static function renderPlayersManual(string $markdown): string
    {
        // Notes for maintainers stay in the repository
        $markdown = preg_replace('/^> \*\*Keep this manual current\.\*\*.*\R/m', '', $markdown);

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'none',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);

        // Images are served by /getting-started/images; other documents are on GitHub
        $html = preg_replace('/src="images\//', 'src="/getting-started/images/', $html);
        return preg_replace('/href="(?!https?:|#|\/)([\w.-]+\.md)/', 'href="' . self::REPOSITORY_DOCS . '$1', $html);
    }

    /** An image used by the players' manual */
    public function playersManualImage(string $file)
    {
        $path = base_path(self::MANUAL_IMAGES . '/' . $file);
        abort_unless(is_file($path), 404);
        return response()->file($path, ['Cache-Control' => 'public, max-age=86400']);
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
     * One query: how to call it
     */
    public function queryCommand($id)
    {
        $query = Query::findOrFail($id);
        return view('query-command', compact('query'));
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
        $query = DB::table('events')->select('events.timestamp', 'acting_agents.name as acting_agent_name', 'acting_agents.company_id as acting_company_id', 'acting_companies.name as acting_company_name',
            'acting_agents.agent_id as acting_agent_id', 'acting_agents.name as acting_agent_name', 'protocols.name as protocol_name', 'affected_companies.name as affected_company_name', 'affected_companies.company_id as affected_company_id',
            'event_types.name as event_type_name', 'affected_agents.agent_id as affected_agent_id', 'affected_agents.name as affected_agent_name', 'resources.id as resource_id',
            'resources.name as resource_name', 'events.locked', 'events.new_quantity', 'events.new_credits', 'events.new_location', 'objects.object_id', 'objects.object_name', 'observations.drone as observing_drone')
            ->join('observations', 'events.id', '=', 'observations.event') //obligatory to be observed
            ->leftJoin('actions', 'events.action', '=', 'actions.id')
            ->leftJoin('agents as acting_agents', 'actions.drone', '=', 'acting_agents.agent_id')
            ->leftJoin('companies as acting_companies', 'acting_agents.company_id', '=', 'acting_companies.company_id')
            ->leftJoin('protocols', 'actions.protocol', '=', 'protocols.id')
            ->leftJoin('event_types', 'events.event_type', '=', 'event_types.id')
            ->leftJoin('agents as affected_agents', 'events.drone', '=', 'affected_agents.agent_id')
            ->leftJoin('companies as affected_companies', 'affected_agents.company_id', '=', 'affected_companies.company_id')
            ->leftJoin('resources', 'events.resource', '=', 'resources.id')
            ->leftJoin('objects', 'events.new_location', '=', 'objects.object_id');
        //basic restrictions
        $query->where([/*['observations.drone', '=', 0],*/ ['observations.time', '<=', Carbon::now()->toDateTimeString()]]);
        if ($request->get('keyword') !== null)
            $query->where(function ($query) use ($request) {
                $query->where('affected_agents.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('acting_agents.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('protocols.name', 'LIKE', "%" . $request->get('keyword') . "%")
                    ->orWhere('affected_companies.name', 'LIKE', "%" . $request->get('keyword') . "%")
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
