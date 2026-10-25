<?php

namespace App\Http\Controllers;

use App\Agent;
use App\Company;
use App\Services\LaunchQueue;
use App\Services\Provenance;
use App\Services\Release;
use App\Services\TestWorld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The Release page (#324 step 6): a player posts a script, which becomes a
 * new company with one drone that runs the script's launched word. The page
 * also shows the player's drones and, with email switched off, their replies.
 */
class ReleaseController extends Controller
{
    public function show()
    {
        if (!Auth::check()) {
            return redirect('/login');
        }
        $user = Auth::user();
        $companies = Company::where('user_id', $user->user_id)->orderBy('company_id', 'desc')->get();
        $drones = Agent::whereIn('company_id', $companies->pluck('company_id'))->orderBy('agent_id', 'desc')->get();

        // Replies that have reached Earth (game time, at the speed of light)
        $messages = collect();
        if ($drones->isNotEmpty()) {
            $messages = DB::table('drone_messages')
                ->whereIn('agent_id', $drones->pluck('agent_id'))
                ->where('game_t', '<=', self::gameNow())
                ->orderBy('id', 'desc')->limit(100)->get()
                ->groupBy('agent_id');
        }

        $testWorld = TestWorld::available();
        $testRuns = $testWorld ? TestWorld::runs($user) : collect();

        // A starter script (#324 step 15) fills the form when asked for, or
        // a drone's code or one of its words (#324 step 16: "Copy this code")
        $starters = Release::STARTERS;
        $starter = Release::starter((string) request('starter'));
        $copiedFrom = null;
        $copiedWord = null;
        if (request()->filled('copy')) {
            $release = Provenance::releaseOf((int) request('copy'));
            if ($release) {
                $copiedFrom = (int) request('copy');
                $copiedWord = request()->filled('word') ? (string) request('word') : null;
                $starter = $copiedWord !== null ? Provenance::wordSource($release->source, $copiedWord) : $release->source;
                if ($starter === null) {
                    $copiedWord = null;
                }
            }
        }

        $queued = LaunchQueue::of($user);
        $clock = LaunchQueue::clock();

        return view('release', compact('companies', 'drones', 'messages', 'testWorld', 'testRuns', 'starters', 'starter', 'copiedFrom', 'copiedWord',
            'queued', 'clock'));
    }

    /**
     * The Test button (#324 step 7): runs the script in the test world, with
     * the same rules and a faster clock, and nothing in the live world
     */
    public function test(Request $request)
    {
        if (!Auth::check()) {
            return redirect('/login');
        }
        if (!TestWorld::available()) {
            return back()->withErrors(['script' => 'The test world is not running right now.'])->withInput();
        }
        $script = $this->validScript($request, false);
        if (!is_string($script)) {
            return $script;
        }

        $agent = TestWorld::run(Auth::user(), $script);

        return redirect('/release#test-runs')->withInput()->withSuccess('Test run started: drone ' . $agent->name
            . ' in the test world is loading your script. Its results appear below as it runs.');
    }

    /**
     * The script from the form when it can be released, else the response
     * sending the player back with what is wrong
     */
    private function validScript(Request $request, bool $needsCompanyName)
    {
        $rules = ['script' => 'required|string|max:' . config('forge.max_script_length')];
        if ($needsCompanyName) {
            $rules['company_name'] = ['required', 'string', 'min:3', 'max:45', 'unique:companies,name',
                function ($attribute, $value, $fail) {
                    if (LaunchQueue::nameWaiting((string) $value)) {
                        $fail('A company of that name is waiting in the launch queue.');
                    }
                }];
        }
        $request->validate($rules);

        $script = str_replace("\r\n", "\n", $request->input('script'));
        $errors = [];
        if (!Release::definesLaunched($script)) {
            $errors['script'] = 'Your script must define the word launched (": launched ... ;"): your drone runs it once it is released.';
        }
        foreach (explode("\n", $script) as $i => $line) {
            if (mb_strlen($line) > \App\Command::MAX_CODE_LENGTH) {
                $errors['script'] = 'Line ' . ($i + 1) . ' is too long: split it into shorter lines.';
                break;
            }
        }
        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }
        return $script;
    }

    public function store(Request $request)
    {
        if (!Auth::check()) {
            return redirect('/login');
        }
        $script = $this->validScript($request, true);
        if (!is_string($script)) {
            return $script;
        }

        // "Copy this code" or "Copy this word" on a Drone page (#324 step 16)
        $copiedFrom = $request->filled('copied_from') ? (int) $request->input('copied_from') : null;
        if ($copiedFrom !== null && !Agent::where('agent_id', $copiedFrom)->exists()) {
            $copiedFrom = null;
        }
        $copiedWord = $copiedFrom !== null && $request->filled('copied_word')
            ? mb_substr((string) $request->input('copied_word'), 0, 64) : null;

        // The world is full (#324 step 20): the release waits its turn
        if (LaunchQueue::full()) {
            $place = LaunchQueue::enqueue(Auth::user(), $request->input('company_name'), $script, $copiedFrom, $copiedWord);
            return redirect('/release')->withSuccess('The world is full right now, so ' . $request->input('company_name')
                . ' waits in the launch queue: it is number ' . $place . ' in line, and launches as soon as there is room.');
        }

        $agent = Release::release(Auth::user(), $request->input('company_name'), $script, null, $copiedFrom, $copiedWord);

        return redirect('/release')->withSuccess('Released! Drone ' . $agent->name . ' of ' . $request->input('company_name')
            . ' is loading your script and will run its launched word.');
    }

    /** The game clock's current game time (game seconds) */
    public static function gameNow(): int
    {
        $clock = DB::table('game_clock')->where('id', 1)->first();
        if (!$clock) {
            return time();
        }
        return (int) round($clock->game_anchor + (time() - $clock->real_anchor) * $clock->scale);
    }
}
