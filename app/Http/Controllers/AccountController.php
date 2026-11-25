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
        // The password once, with a Show toggle to check it (#1041)
        $request->validate(['name' => 'required|string|max:64', 'email' => 'required|string|max:255',
            'password' => 'required|string|max:200']);
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
        // A visitor who test drove before signing up has earned Test Pilot (#1048)
        if (session(\App\Services\Pipeline::TRIED)) {
            \App\Services\Badges::award('player', (int) $user->user_id, 'test_pilot');
        }
        Auth::login($user);
        $request->session()->regenerate();
        $sent = Players::sendVerification($user);
        // Straight on to the next step (#1041): founding a startup, with the drone they test drove if any
        return redirect('/release')->withSuccess('Welcome, ' . $user->login . '! '
            . (\App\Services\GuestDrive::draft() ? 'Your drone is saved: found it as a startup below. ' : 'Next: found your first startup. ')
            . ($sent ? 'We have sent a link to your e-mail address: open it to verify your account.'
                     : 'We couldn\'t send the link to verify your e-mail address just now: send it again from your account page.'));
    }

    public function showLogin()
    {
        return Auth::check() ? redirect('/account') : view('account.login');
    }

    public function login(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255', 'password' => 'required|string|max:200']);
        $user = Players::authenticate((string) $request->input('name'), (string) $request->input('password'));
        if (!$user) {
            return back()->withErrors(['name' => 'That name or e-mail address and password don\'t match an account.'])->withInput($request->only('name'));
        }
        Auth::login($user);
        $request->session()->regenerate();
        // While you were away (#640): seen only once the account page shows it (#655)
        $request->session()->flash('digest', \App\Services\PlayerBoards::digest($user, false));
        return redirect()->intended('/account');
    }

    /** The forgotten-password form (#910) */
    public function showForgot()
    {
        return Auth::check() ? redirect('/account') : view('account.forgot');
    }

    /**
     * Sends a reset link to the account's verified address. The reply is the
     * same whether or not there is such an account, so the form can't tell
     * anyone who plays.
     */
    public function forgot(Request $request)
    {
        $request->validate(['account' => 'required|string|max:255']);
        $user = Players::forReset((string) $request->input('account'));
        if ($user) {
            Players::sendReset($user);
        }
        return redirect('/password/forgot')->withSuccess('If that is an account with a verified e-mail address, we have sent it a link to '
            . 'choose a new password. It works for an hour. Nothing there? Check your spam folder.');
    }

    /** The page the reset link opens: a new password, twice */
    public function showReset(Request $request, int $id, string $hash)
    {
        $user = Players::resetting($id, $hash);
        if (!$user) {
            return redirect('/password/forgot')->with('error', 'That link has been used or no longer works. Ask for a new one.');
        }
        return view('account.reset', ['name' => $user->login, 'action' => $request->getRequestUri()]);
    }

    /** Sets the new password, ends the account's other sessions and logs it in here */
    public function reset(Request $request, int $id, string $hash)
    {
        $user = Players::resetting($id, $hash);
        if (!$user) {
            return redirect('/password/forgot')->with('error', 'That link has been used or no longer works. Ask for a new one.');
        }
        $request->validate(['password' => 'required|string|max:200|confirmed']);
        try {
            Players::resetPassword($user, (string) $request->input('password'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }
        $user = $user->fresh();
        Auth::login($user);
        $request->session()->regenerate();
        return redirect('/account')->withSuccess('Your new password is set, and the account is logged out everywhere else. '
            . 'Its API token is cancelled: make a new one below if a script uses it.');
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
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        \App\Services\SinceLooked::seen($user);
        return back();
    }

    /** POST /account/bot-keys: an operator's new key for Claude players, shown once (#1025) */
    public function makeBotKey()
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $key = \App\Services\Bots::makeKey($user);
        return $key === null ? redirect('/account')->with('error', 'Only an operator can make keys for Claude players.')
            : redirect('/account#claude-players')->with('bot_key', $key);
    }

    /** POST /account/bot-keys/{id}/revoke (#1025) */
    public function revokeBotKey(int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        return \App\Services\Bots::revokeKey($user, $id) ? redirect('/account#claude-players')->withSuccess('The key is revoked.')
            : redirect('/account#claude-players')->with('error', 'No such key of yours stands.');
    }

    public function show()
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        if (is_array($digest = session('digest'))) {
            \App\Services\PlayerBoards::markSeen($user, $digest);
        }
        return view('account.show', ['account' => Players::summary($user), 'mine' => \App\Services\Startups::of($user),
            'portfolio' => \App\Services\PlayerShares::of($user),
            // The account trading script (#768)
            'tradeScript' => \App\Services\TradeScripts::available() ? (\App\Services\TradeScripts::of($user) ?? ['code' => '', 'enabled' => false, 'runs' => 0, 'last_run' => null, 'log' => []]) : null,
            // Claude players (#1025): an operator's keys and players
            'bots' => \App\Services\Bots::isOperator($user) ? ['keys' => \App\Services\Bots::keys($user),
                'players' => \App\Services\Bots::players($user)] : null]);
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
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
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
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        return redirect('/account')->with('token', Players::issueToken($user));
    }

    /** A new class group of pupils' accounts: their names and passwords, shown once */
    public function createClassGroup(Request $request)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
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
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $group = Players::classGroup($user, $id);
        abort_if($group === null, 404);
        // Class settings and the log of what passed between pupils (#1003)
        return view('account.class-group', ['group' => $group, 'fresh' => false,
            'settings' => \App\Services\ClassSettings::available() ? \App\Services\ClassSettings::of($id) : null,
            'log' => \App\Services\ClassSettings::log($id)]);
    }

    /** POST /account/classes/{id}/settings {takeovers, loans_within}: a teacher's class settings (#1003) */
    public function classSettings(Request $request, int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        abort_unless(\App\Services\ClassSettings::save($user, $id, $request->boolean('takeovers'), $request->boolean('loans_within')), 404);
        return redirect('/account/classes/' . $id . '#class-settings')->withSuccess('The class settings are saved.');
    }

    /** A new password for one pupil, shown once */
    public function resetPupil(Request $request, int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
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
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        if (!\App\Services\Notices::available() || !Players::verified($user)
            || \Illuminate\Support\Facades\DB::table('users')->where('user_id', $user->user_id)->value('class_group_id') !== null) {
            return redirect('/account#notices')->with('error', 'E-mail notices need an account with a verified e-mail address.');
        }
        $on = array_values(array_intersect((array) $request->input('notices', []), array_keys(\App\Services\Notices::KINDS)));
        \App\Services\Notices::save($user, $on);
        return redirect('/account#notices')->withSuccess($on ? 'Your e-mail notices are saved.' : 'You will get no e-mail notices.');
    }

    /** A House price alert (#761, part 2) */
    public function addAlert(Request $request)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        abort_unless(\App\Services\Notices::available(), 404);
        $request->validate(['resource' => 'required|integer', 'place' => 'nullable|integer', 'above' => 'required|integer|min:1|max:100000000']);
        $problem = \App\Services\Notices::addAlert($user, (int) $request->input('resource'),
            $request->filled('place') ? (int) $request->input('place') : null, (int) $request->input('above'));
        return $problem !== null ? redirect('/account#notices')->withErrors(['alert' => $problem])
            : redirect('/account#notices')->withSuccess('Your alert is set. Turn on "' . \App\Services\Notices::KINDS['price_alerts'] . '" to get it.');
    }

    public function removeAlert(int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        abort_unless(\App\Services\Notices::available() && \App\Services\Notices::removeAlert($user, $id), 404);
        return redirect('/account#notices')->withSuccess('The alert is gone.');
    }

    /** A teacher's daily class summary on or off (#761) */
    public function classNotices(Request $request, int $id)
    {
        $user = $this->player();
        if (!$user) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
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
