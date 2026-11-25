<?php

namespace App\Http\Controllers;

use App\Services\GuestDrive;
use App\Services\Release;
use App\Services\Replay;
use Illuminate\Http\Request;

/**
 * Try a drone before you have an account (#1042, part of #1039): pick a
 * starter, change it, test drive it as a new startup's drone would run it,
 * and keep it. Keeping it sends a visitor to sign up (#1041) and a player
 * to the Found page, which the drone fills.
 */
class TryController extends Controller
{
    public function show()
    {
        $starters = GuestDrive::starters();
        $draft = GuestDrive::draft();
        $asked = preg_replace('/\.lua$/', '', (string) request('starter'));
        if (array_key_exists($asked, $starters) && (!$draft || $draft['starter'] !== $asked)) {
            $draft = ['starter' => $asked, 'script' => (string) Release::starter($asked)];
        }
        // The first drone, the Shuttle, unless they asked for another (#1050)
        $draft ??= ['starter' => Replay::FIRST, 'script' => (string) Release::starter(Replay::FIRST)];
        $script = old('script', $draft['script']);
        return view('pages.try', ['starters' => $starters, 'starter' => $draft['starter'], 'script' => $script,
            'drive' => session('guest_drive'),
            // The replay is of the starter as it comes: not beside a visitor's own changes
            'replay' => $script === Release::starter($draft['starter']) ? Replay::of($draft['starter']) : null,
            // Routes for the Shuttle's settings, to choose from (#1050)
            'routes' => $draft['starter'] === 'shuttle' ? \App\Services\Routes::best(2) : []]);
    }

    public function drive(Request $request)
    {
        [$starter, $script] = $this->draftFrom($request);
        if (!is_string($script)) {
            return $script;
        }
        GuestDrive::keep($starter, $script);
        if (!GuestDrive::allow((string) $request->ip())) {
            return redirect('/try#result')->with('error', 'That is a lot of test drives for one hour: try again in a few minutes, '
                . 'or keep your drone and found it, which is free.');
        }
        session([\App\Services\Pipeline::TRIED => true]);   // step 2 of the ladder (#1043)
        if (($player = auth()->user()) && $player->login !== null) {
            \App\Services\Badges::award('player', (int) $player->user_id, 'test_pilot');   // #1048
        }
        return redirect('/try#result')->with('guest_drive', GuestDrive::run($script));
    }

    /** Keep the drone: sign up for it, or (a player) found it */
    public function keep(Request $request)
    {
        [$starter, $script] = $this->draftFrom($request);
        if (!is_string($script)) {
            return $script;
        }
        GuestDrive::keep($starter, $script);
        $player = auth()->user();
        return redirect($player && $player->login !== null ? '/release' : '/signup');
    }

    /** [starter, script] from the form, or [null, the response sending the visitor back] */
    private function draftFrom(Request $request): array
    {
        $request->validate(['script' => 'required|string|max:' . config('forge.max_script_length'),
            'starter' => 'nullable|string|max:32']);
        $starter = (string) $request->input('starter');
        $starter = array_key_exists($starter, GuestDrive::starters()) ? $starter : 'shuttle';
        $script = str_replace("\r\n", "\n", (string) $request->input('script'));
        if ($problem = Release::scriptProblem($script)) {
            return [null, redirect('/try#editor')->withErrors(['script' => $problem])->withInput()];
        }
        return [$starter, $script];
    }
}
