<?php

namespace App\Http\Controllers;

use App\Agent;
use App\Services\LaunchQueue;
use App\Services\Provenance;
use App\Services\Release;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Release page (#324 step 6): anyone posts a script (#367 step 3: no
 * accounts), which becomes a new company with one drone that runs the
 * script's launched word. The drone's page follows it from there.
 */
class ReleaseController extends Controller
{
    public function show()
    {
        // A starter script (#324 step 15) fills the form when asked for, or
        // a drone's code or one of its words (#324 step 16: "Copy this code")
        $starters = Release::STARTERS;
        $starter = Release::starter((string) request('starter'));
        $kit = Release::STARTER_KITS[preg_replace('/\.lua$/', '', (string) request('starter'))] ?? 'mining';
        $copiedFrom = null;
        $copiedWord = null;
        if (request()->filled('copy')) {
            $release = Provenance::releaseOf((int) request('copy'));
            if ($release) {
                $copiedFrom = (int) request('copy');
                // The kit the copied drone's company started with
                $started = \Illuminate\Support\Facades\DB::table('agents as a')->join('companies as c', 'c.company_id', '=', 'a.company_id')
                    ->where('a.agent_id', $copiedFrom)->value('c.start_kit');
                $kit = array_search((int) $started, Release::KITS, true) ?: $kit;
                $copiedWord = request()->filled('word') ? (string) request('word') : null;
                $starter = $copiedWord !== null ? Provenance::wordSource($release->source, $copiedWord) : $release->source;
                if ($starter === null) {
                    $copiedWord = null;
                }
            }
        }

        $clock = LaunchQueue::clock();

        $kits = Release::KIT_NAMES;

        return view('release', compact('starters', 'starter', 'copiedFrom', 'copiedWord', 'clock', 'kits', 'kit'));
    }

    /**
     * The script from the form when it can be released, else the response
     * sending the player back with what is wrong
     */
    private function validScript(Request $request, bool $needsCompanyName)
    {
        $rules = ['script' => 'required|string|max:' . config('forge.max_script_length'),
                  'kit' => 'nullable|in:' . implode(',', array_keys(Release::KITS)),
                  'password' => 'nullable|string|min:' . config('forge.push_password_min') . '|max:200'];
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
        if ($problem = Release::scriptProblem($script)) {
            return back()->withErrors(['script' => $problem])->withInput();
        }
        return $script;
    }

    public function store(Request $request)
    {
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

        // A password lets its owner push new code later (#553): kept as a hash
        $hash = \App\Services\CodePush::hash($request->filled('password') ? (string) $request->input('password') : null);

        // The world is full (#324 step 20): the release waits its turn
        if (LaunchQueue::full()) {
            $place = LaunchQueue::enqueue(Release::owner(), $request->input('company_name'), $script, $copiedFrom, $copiedWord,
                $request->input('kit'), $hash);
            return redirect('/release')->withSuccess('The world is full right now, so ' . $request->input('company_name')
                . ' waits in the launch queue: it is number ' . $place . ' in line, and launches as soon as there is room.');
        }

        // Anyone may release (#367 step 3): the drone's page is where to follow it
        $agent = Release::release(Release::owner(), $request->input('company_name'), $script, null, $copiedFrom, $copiedWord,
            $request->input('kit'));
        \App\Services\CodePush::setHash((int) $agent->company_id, $hash);

        return redirect('/spacecraft/' . $agent->agent_id)->withSuccess('Released! Drone ' . $agent->name . ' of '
            . $request->input('company_name') . ' is loading your script and will run its launched function. '
            . 'Keep this page\'s link to follow it.')
            ->with('warning', Release::redefinedWarning($script));
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
