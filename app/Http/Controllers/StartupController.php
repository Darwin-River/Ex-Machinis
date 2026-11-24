<?php

namespace App\Http\Controllers;

use App\Services\Startups;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A player's startups on the website (#637): claim a guest's, reprogram,
 * close and mature. Behind the new-rules switch (#635).
 */
class StartupController extends Controller
{
    private function player(): ?\App\User
    {
        $user = Auth::user();
        return $user && $user->login !== null ? $user : null;
    }

    private function done(array $result, string $success)
    {
        return $result['ok']
            ? redirect('/account#startups')->withSuccess($success)
            : redirect('/account#startups')->with('error', $result['error']);
    }

    public function claim(Request $request)
    {
        if (!$user = $this->player()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $result = Startups::claim($user, (string) $request->input('code'));
        return $this->done($result, $result['ok'] ? $result['name'] . ' is yours now.' : '');
    }

    public function reprogram(Request $request, int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $request->validate(['script' => 'required|string|max:' . config('forge.max_script_length')]);
        $result = Startups::reprogram($user, $id, str_replace("\r\n", "\n", (string) $request->input('script')));
        return $this->done($result, $result['ok'] ? 'The new code is on its way to ' . count($result['drones'])
            . ' drone(s), for free.' : '');
    }

    /**
     * POST /startups/{id}/test-drive {script}: the draft run read-only against
     * the world now on the company's first working drone (#1007). Its
     * owner's alone: the snapshot shows the drone's holdings
     */
    public function testDrive(Request $request, int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $request->validate(['script' => 'required|string|max:' . config('forge.max_script_length')]);
        if (!\App\Services\DroneTestDrive::mayDrive($user, $id)) {
            return redirect('/account#startups')->with('error', 'Only a company\'s owner can test drive its drones.');
        }
        $drive = \App\Services\DroneTestDrive::run($id, str_replace("\r\n", "\n", (string) $request->input('script')));
        if ($drive === null) {
            return back()->with('error', 'This company has no working drone to test drive.')->withInput();
        }
        return redirect('/companies/' . $id . '#drone-test-drive')->with('drone_test_drive', $drive)->withInput();
    }

    public function close(int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        // The Close button pushes code that calls close_company() (#925)
        $result = \App\Services\Closures::button($user, $id);
        return $this->done($result, $result['ok'] ? 'Its drones are on their way to closing it: code that calls close_company() '
            . 'reaches them at the speed of light, and the company ends within a real minute after.' : '');
    }

    public function mature(int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $result = Startups::mature($user, $id);
        return $this->done($result, $result['ok'] ? $result['name'] . ' has matured: it repaid ' . number_format($result['repaid'])
            . ' credits, and its ' . number_format($result['shares']) . ' shares are yours.' : '');
    }

    /** The startup race (#776): recent startups' credits by age, against the line and the starters' usual paths */
    public function race()
    {
        $race = \App\Services\StartupRace::build();
        $me = auth()->check() ? (int) auth()->user()->user_id : null;
        return view('world.race', compact('race', 'me'));
    }

    public function raceApi(): \Illuminate\Http\JsonResponse
    {
        return response()->json(\App\Services\StartupRace::build());
    }
}
