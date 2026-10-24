<?php

namespace App\Http\Controllers;

use App\Agent;
use App\Company;
use App\Services\Release;
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

        return view('release', compact('companies', 'drones', 'messages'));
    }

    public function store(Request $request)
    {
        if (!Auth::check()) {
            return redirect('/login');
        }
        $max = config('forge.max_script_length');
        $request->validate([
            'company_name' => 'required|string|min:3|max:45|unique:companies,name',
            'script' => 'required|string|max:' . $max,
        ]);

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

        $agent = Release::release(Auth::user(), $request->input('company_name'), $script);

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
