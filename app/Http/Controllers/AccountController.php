<?php

namespace App\Http\Controllers;

use App\Services\Players;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Player accounts on the website (#636): sign up with a name and a password,
 * log in and out, the account page, an API token, and teachers' class
 * groups. Behind the new-rules switch (#635).
 */
class AccountController extends Controller
{
    public function showSignup()
    {
        return Auth::check() ? redirect('/account') : view('account.signup');
    }

    public function signup(Request $request)
    {
        $request->validate(['name' => 'required|string|max:64', 'email' => 'required|string|max:255',
            'password' => 'required|string|max:200|confirmed']);
        if (!Players::maySignUp((string) $request->ip())) {
            return back()->withErrors(['name' => 'Too many new accounts from your address this hour. Try again later.'])->withInput($request->except('password', 'password_confirmation'));
        }
        try {
            $user = Players::create((string) $request->input('name'), (string) $request->input('password'),
                (string) $request->input('email'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput($request->except('password', 'password_confirmation'));
        }
        Players::countSignUp((string) $request->ip());
        Auth::login($user);
        $request->session()->regenerate();
        $sent = Players::sendVerification($user);
        return redirect('/account')->withSuccess('Welcome, ' . $user->login . '! '
            . ($sent ? 'We have sent a link to your e-mail address: open it to verify your account.'
                     : 'We couldn\'t send the link to verify your e-mail address just now: send it again from this page.'));
    }

    public function showLogin()
    {
        return Auth::check() ? redirect('/account') : view('account.login');
    }

    public function login(Request $request)
    {
        $request->validate(['name' => 'required|string|max:64', 'password' => 'required|string|max:200']);
        $user = Players::authenticate((string) $request->input('name'), (string) $request->input('password'));
        if (!$user) {
            return back()->withErrors(['name' => 'That name and password don\'t match an account.'])->withInput($request->only('name'));
        }
        Auth::login($user);
        $request->session()->regenerate();
        // While you were away (#640): seen only once the account page shows it (#655)
        $request->session()->flash('digest', \App\Services\PlayerBoards::digest($user, false));
        return redirect()->intended('/account');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/')->withSuccess('You have logged out.');
    }

    /** "Since you last looked" (#771): the player has seen it, and the next starts from now */
    public function seen()
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        \App\Services\SinceLooked::seen($user);
        return back();
    }

    public function show()
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        if (is_array($digest = session('digest'))) {
            \App\Services\PlayerBoards::markSeen($user, $digest);
        }
        return view('account.show', ['account' => Players::summary($user), 'mine' => \App\Services\Seedlings::of($user),
            'portfolio' => \App\Services\PlayerShares::of($user),
            // The account trading script (#768)
            'tradeScript' => \App\Services\TradeScripts::available() ? (\App\Services\TradeScripts::of($user) ?? ['code' => '', 'enabled' => false, 'runs' => 0, 'last_run' => null, 'log' => []]) : null]);
    }

    /** The link from the e-mail: verifies the address (the route checks the signature) */
    public function verify(Request $request, int $id, string $hash)
    {
        $user = Players::verify($id, $hash);
        if (!$user) {
            return redirect(Auth::check() ? '/account' : '/login')->with('error', 'That link doesn\'t verify an account.');
        }
        return redirect(Auth::check() ? '/account' : '/login')
            ->withSuccess('Your e-mail address is verified: your credits for buying shares are in your account.');
    }

    /** Sends the verification link again (a few times an hour) */
    public function resendVerification(Request $request)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        $key = 'verify-mail|' . $user->user_id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
            return redirect('/account')->with('error', 'We have sent the link several times this hour. Check your spam folder, or try again later.');
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 3600);
        return Players::sendVerification($user)
            ? redirect('/account')->withSuccess('We have sent the link again.')
            : redirect('/account')->with('error', 'We couldn\'t send the link just now. Try again later.');
    }

    /** A new API token, shown once */
    public function token()
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        return redirect('/account')->with('token', Players::issueToken($user));
    }

    /** A new class group of pupils' accounts: their names and passwords, shown once */
    public function createClassGroup(Request $request)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        $request->validate(['group_name' => 'required|string|max:64', 'pupils' => 'required|integer']);
        try {
            $group = Players::createClassGroup($user, (string) $request->input('group_name'), (int) $request->input('pupils'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['group_name' => $e->getMessage()])->withInput();
        }
        return view('account.class-group', ['group' => $group, 'fresh' => true]);
    }

    public function classGroup(int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        $group = Players::classGroup($user, $id);
        abort_if($group === null, 404);
        return view('account.class-group', ['group' => $group, 'fresh' => false]);
    }

    /** A new password for one pupil, shown once */
    public function resetPupil(Request $request, int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        $group = Players::classGroup($user, $id);
        abort_if($group === null, 404);
        $pupil = (string) $request->input('pupil');
        $password = in_array($pupil, $group['pupils'], true) ? Players::resetPupilPassword($user, $pupil) : null;
        if ($password === null) {
            return back()->withErrors(['pupil' => 'That account isn\'t in this class group.']);
        }
        return redirect('/account/classes/' . $id)->with('reset', ['name' => $pupil, 'password' => $password]);
    }

    /** Which e-mail notices the player wants (#761) */
    public function notices(Request $request)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        if (!\App\Services\Notices::available() || !Players::verified($user)
            || \Illuminate\Support\Facades\DB::table('users')->where('user_id', $user->user_id)->value('class_group_id') !== null) {
            return redirect('/account#notices')->with('error', 'E-mail notices need an account with a verified e-mail address.');
        }
        $on = array_values(array_intersect((array) $request->input('notices', []), array_keys(\App\Services\Notices::KINDS)));
        \App\Services\Notices::save($user, $on);
        return redirect('/account#notices')->withSuccess($on ? 'Your e-mail notices are saved.' : 'You will get no e-mail notices.');
    }

    /** A teacher's daily class summary on or off (#761) */
    public function classNotices(Request $request, int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login');
        }
        abort_unless(\App\Services\Notices::available() && \App\Services\Notices::setClass($user, $id, $request->boolean('on')), 404);
        return redirect('/account#notices')->withSuccess($request->boolean('on')
            ? 'You will get a daily summary for this class when something needs your pupils.' : 'No more summaries for this class.');
    }

    /** The link in every notice: turns them all off, no login needed (the route checks the signature) */
    public function unsubscribe(int $id)
    {
        \App\Services\Notices::unsubscribe($id);
        return redirect(Auth::check() ? '/account#notices' : '/login')
            ->withSuccess('You will get no more e-mail notices. You can turn them on again on your account page.');
    }

    /** The logged-in player, if any (the game's own owners can't log in) */
    private function player(): ?\App\User
    {
        $user = Auth::user();
        return $user && $user->login !== null ? $user : null;
    }
}
