<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Player accounts (#636, step 1 of the new rules in #635): a name, a
 * password and an e-mail address, one account per address. Verifying the
 * address (a link sent to it) brings the account its credits that can only
 * buy shares (config forge.player_share_credits); what it earns later
 * (share sales, dividends) goes to its ordinary credits. The address is
 * private: only its account sees it. A teacher with a verified account makes
 * a class group of pseudonymous pupils' accounts, which have no e-mail and
 * get their share credits at once, and can give a pupil a new password. The
 * game's own owners (the Public Forge owner, the House) are users too, but
 * have no login and can't sign in.
 */
class Players
{
    /** A name: letters, digits, spaces and . _ -, starting with a letter or digit */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._-]{2,31}$/';

    public static function enabled(): bool
    {
        return (bool) config('forge.new_rules');
    }

    /** Why a name can't be an account's, or null when it can */
    public static function nameProblem(string $name): ?string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return 'A name is 3 to 32 letters, digits, spaces, dots, dashes or underscores, starting with a letter or digit.';
        }
        if (User::where('login', $name)->exists()) {   // the column's collation ignores case
            return 'That name is taken.';
        }
        return null;
    }

    /** Why a password won't do, or null */
    public static function passwordProblem(string $password): ?string
    {
        $min = (int) config('forge.player_password_min');
        return mb_strlen($password) < $min ? "A password has at least $min characters." : null;
    }

    /** An address as stored: trimmed and in lower case */
    public static function normalEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Why an address can't be an account's, or null when it can */
    public static function emailProblem(string $email): ?string
    {
        $email = self::normalEmail($email);
        if (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'That doesn\'t look like an e-mail address.';
        }
        if (DB::table('users')->where('email', $email)->exists()) {
            return 'An account already uses that e-mail address.';
        }
        return null;
    }

    /**
     * A new account. A player's account needs an e-mail address, and gets its
     * starting share credits once the address is verified; a pupil's (in a
     * class group) has none and gets them at once. Throws
     * InvalidArgumentException with the reason when something won't do.
     */
    public static function create(string $name, string $password, ?string $email, ?int $classGroup = null, ?int $botOperator = null): User
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($problem = self::nameProblem($name) ?? self::passwordProblem($password)) {
            throw new \InvalidArgumentException($problem);
        }
        if ($classGroup === null && $botOperator === null) {
            if ($problem = self::emailProblem((string) $email)) {
                throw new \InvalidArgumentException($problem);
            }
            $email = self::normalEmail((string) $email);
        } else {
            $email = null;
        }
        $id = DB::table('users')->insertGetId([
            'name' => $name,
            'login' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'credits' => 0,
            'share_credits' => $classGroup === null ? 0 : (int) config('forge.player_share_credits'),
            'class_group_id' => $classGroup,
            // A Claude player (#1025) is verified without an address
            'email_verified_at' => $botOperator !== null ? now() : null,
            // Its first digest starts now (#655)
            'last_seen_t' => World::gameNow(),
            'last_seen_trade' => (int) (DB::table('trades')->max('id') ?? 0),
            'created_at' => now(),
            'updated_at' => now(),
        ] + ($botOperator !== null ? ['bot_operator_id' => $botOperator] : []));
        // A trial without mail (#642): verified at once
        if ($classGroup === null && $botOperator === null && config('forge.trial_auto_verify')) {
            self::verify($id, sha1($email));
        }
        return User::where('user_id', $id)->firstOrFail();
    }

    /**
     * The account with this name, or this e-mail address (#913: a name has no
     * "@"), and this password, or null
     */
    public static function authenticate(string $name, string $password): ?User
    {
        $given = trim($name);
        $user = str_contains($given, '@')
            ? User::where('email', self::normalEmail($given))->whereNotNull('login')->first()
            : User::where('login', $given)->first();
        return $user && Hash::check($password, (string) $user->password) ? $user : null;
    }

    /** Is the account verified: its address, or (a pupil's) its teacher's? */
    public static function verified(User $user): bool
    {
        $row = DB::table('users')->where('user_id', $user->user_id)->first(['email_verified_at', 'class_group_id']);
        return $row && ($row->email_verified_at !== null || $row->class_group_id !== null);
    }

    /** The link that verifies an account's address: good for 2 real days */
    public static function verificationUrl(User $user): string
    {
        $email = (string) DB::table('users')->where('user_id', $user->user_id)->value('email');
        // Signed over its path alone, so it checks out behind the server's
        // HTTPS proxy whatever scheme and host the website sees
        return url(URL::temporarySignedRoute('account.verify', now()->addDays(2),
            ['id' => $user->user_id, 'hash' => sha1($email)], false));
    }

    /**
     * Sends the account its verification link. False when the account has
     * nothing to verify or the mail couldn't be sent (logged).
     */
    public static function sendVerification(User $user): bool
    {
        $row = DB::table('users')->where('user_id', $user->user_id)->first();
        if (!$row || $row->email === null || $row->email_verified_at !== null) {
            return false;
        }
        try {
            Mail::to($row->email)->send(new \App\Mail\VerifyAccount((string) $row->login, self::verificationUrl($user)));
            return true;
        } catch (\Throwable $e) {
            Log::warning('Account verification mail failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifies an account from its link's id and hash (the link's signature
     * is checked by the route). The first time, the account gets its starting
     * share credits. Returns the account, or null when the hash doesn't
     * match its address.
     */
    public static function verify(int $id, string $hash): ?User
    {
        return DB::transaction(function () use ($id, $hash) {
            $row = DB::table('users')->where('user_id', $id)->whereNotNull('login')->lockForUpdate()->first();
            if (!$row || $row->email === null || !hash_equals(sha1($row->email), $hash)) {
                return null;
            }
            if ($row->email_verified_at === null) {
                DB::table('users')->where('user_id', $id)->update(['email_verified_at' => now(),
                    'share_credits' => DB::raw('share_credits + ' . (int) config('forge.player_share_credits')),
                    'updated_at' => now()]);
                CreditFlows::record('share_credits_granted', (int) config('forge.player_share_credits'), null, $id);   // #937
                // Told when a startup can mature or passes its cap, unless turned off (#876)
                if (\Illuminate\Support\Facades\Schema::hasTable('notice_settings')) {
                    DB::table('notice_settings')->insertOrIgnore(['user_id' => $id, 'can_mature' => 1, 'at_cap' => 1, 'updated_at' => now()]);
                }
            }
            return User::where('user_id', $id)->first();
        });
    }

    /** How long a password reset link works (#910) */
    public const RESET_MINUTES = 60;

    /**
     * The account a forgotten-password form names, by its name or its
     * address, or null. Only a player's account with a verified address
     * (#910): a pupil's has none, and an unverified one may not be theirs.
     */
    public static function forReset(string $nameOrEmail): ?User
    {
        $given = trim($nameOrEmail);
        if ($given === '') {
            return null;
        }
        $query = User::whereNotNull('email_verified_at')->whereNull('class_group_id');
        $user = str_contains($given, '@')
            ? $query->where('email', self::normalEmail($given))->first()
            : $query->where('login', $given)->first();
        return $user && $user->email !== null ? $user : null;
    }

    /**
     * The hash a reset link carries: over the account's password hash, so the
     * link stops working once the password changes, whether by this link or
     * any other way
     */
    private static function resetHash(User $user): string
    {
        return hash_hmac('sha256', 'reset|' . $user->user_id . '|' . (string) $user->password, (string) config('app.key'));
    }

    /** The link that lets an account choose a new password: good for an hour, once */
    public static function resetUrl(User $user): string
    {
        return url(URL::temporarySignedRoute('password.reset', now()->addMinutes(self::RESET_MINUTES),
            ['id' => $user->user_id, 'hash' => self::resetHash($user)], false));
    }

    /**
     * Sends an account its reset link, at most 3 times an hour. False when it
     * wasn't sent (too many, or the mail failed: logged).
     */
    public static function sendReset(User $user): bool
    {
        $key = 'reset-mail|' . $user->user_id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return false;
        }
        RateLimiter::hit($key, 3600);
        try {
            Mail::to((string) $user->email)->send(new \App\Mail\ResetPassword((string) $user->login, self::resetUrl($user)));
            return true;
        } catch (\Throwable $e) {
            Log::warning('Password reset mail failed: ' . $e->getMessage());
            return false;
        }
    }

    /** The account a reset link is for, while its hash still matches, or null */
    public static function resetting(int $id, string $hash): ?User
    {
        $user = User::where('user_id', $id)->whereNotNull('login')->first();
        return $user && hash_equals(self::resetHash($user), $hash) ? $user : null;
    }

    /**
     * Sets an account's new password from a reset link. Its API token and
     * "remember me" are cleared, and its other sessions end (the website's
     * AuthenticateSession sees the password change). Throws
     * InvalidArgumentException when the password won't do.
     */
    public static function resetPassword(User $user, string $password): void
    {
        if ($problem = self::passwordProblem($password)) {
            throw new \InvalidArgumentException($problem);
        }
        DB::table('users')->where('user_id', $user->user_id)
            ->update(['password' => Hash::make($password), 'api_token' => null, 'remember_token' => null, 'updated_at' => now()]);
    }

    /**
     * A new API token for an account: shown once, kept as a hash. A new one
     * replaces the old.
     */
    public static function issueToken(User $user): string
    {
        $token = Str::random(48);
        DB::table('users')->where('user_id', $user->user_id)->update(['api_token' => hash('sha256', $token)]);
        return $token;
    }

    /** The account a token belongs to, or null */
    public static function byToken(?string $token): ?User
    {
        $user = $token === null || $token === '' ? null
            : User::where('api_token', hash('sha256', $token))->whereNotNull('login')->first();
        // Or a Claude player its operator's key acts for (#1030)
        return $user ?? Bots::actingPlayer();
    }

    /**
     * May this address make another account now? (forge.player_signups_per_hour
     * from one address in an hour)
     */
    public static function maySignUp(string $ip): bool
    {
        return !RateLimiter::tooManyAttempts('signup|' . $ip, max(1, (int) config('forge.player_signups_per_hour')));
    }

    public static function countSignUp(string $ip): void
    {
        RateLimiter::hit('signup|' . $ip, 3600);
    }

    /** What an account's page and the API show of it */
    public static function summary(User $user): array
    {
        $row = DB::table('users')->where('user_id', $user->user_id)->first();
        $group = $row->class_group_id ? DB::table('class_groups')->where('id', $row->class_group_id)->first() : null;
        $teaches = DB::table('class_groups')->where('teacher_id', $user->user_id)->orderBy('id')->get()
            ->map(fn ($g) => ['id' => (int) $g->id, 'name' => $g->name,
                'pupils' => DB::table('users')->where('class_group_id', $g->id)->count(),
                'notices' => (bool) ($g->notices ?? false)])->all();
        return [
            'name' => $row->login,
            'email' => $row->email,
            'verified' => $row->email_verified_at !== null || $row->class_group_id !== null,
            'credits' => (int) $row->credits,
            'share_credits' => (int) $row->share_credits,
            'class_group' => $group ? ['id' => (int) $group->id, 'name' => $group->name] : null,
            'teaches' => $teaches,
            'since' => $row->created_at,
        ];
    }

    /**
     * A teacher's new class group with this many pupils' accounts, named
     * after the group ("Orbit 1", "Orbit 2"...), each with a generated
     * password. Returns the group's id and the names and passwords, which
     * are shown once. Throws InvalidArgumentException with the reason.
     */
    public static function createClassGroup(User $teacher, string $name, int $pupils): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $max = (int) config('forge.class_group_max');
        if (!self::verified($teacher) || DB::table('users')->where('user_id', $teacher->user_id)->value('class_group_id') !== null) {
            throw new \InvalidArgumentException('Verify your e-mail address before you make a class group.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{2,24}$/', $name)) {
            throw new \InvalidArgumentException('A class group\'s name is 3 to 25 letters, digits, spaces, dots, dashes or underscores.');
        }
        if ($pupils < 1 || $pupils > $max) {
            throw new \InvalidArgumentException("A class group has 1 to $max pupils.");
        }
        if (DB::table('class_groups')->where('teacher_id', $teacher->user_id)->where('name', $name)->exists()) {
            throw new \InvalidArgumentException('You already have a class group of that name.');
        }
        // Its pupils' names must all be free
        $names = [];
        for ($i = 1; $i <= $pupils; $i++) {
            $names[] = $name . ' ' . $i;
        }
        $taken = User::whereIn('login', $names)->pluck('login')->all();
        if ($taken) {
            throw new \InvalidArgumentException('These names are taken: ' . implode(', ', $taken) . '. Choose another name for the group.');
        }
        return DB::transaction(function () use ($teacher, $name, $names) {
            $group = DB::table('class_groups')->insertGetId(['name' => $name, 'teacher_id' => $teacher->user_id, 'created_at' => now()]);
            $accounts = [];
            foreach ($names as $login) {
                $password = self::readablePassword();
                self::create($login, $password, null, $group);
                $accounts[] = ['name' => $login, 'password' => $password];
            }
            return ['id' => $group, 'name' => $name, 'accounts' => $accounts];
        });
    }

    /** The pupils' names in a teacher's class group, or null if it isn't theirs */
    public static function classGroup(User $teacher, int $group): ?array
    {
        $row = DB::table('class_groups')->where('id', $group)->where('teacher_id', $teacher->user_id)->first();
        if (!$row) {
            return null;
        }
        return ['id' => (int) $row->id, 'name' => $row->name,
            'pupils' => DB::table('users')->where('class_group_id', $group)->orderBy('user_id')->pluck('login')->all()];
    }

    /**
     * A new password for one of a teacher's pupils, shown once; null when the
     * account isn't in one of the teacher's groups
     */
    public static function resetPupilPassword(User $teacher, string $pupil): ?string
    {
        $row = DB::table('users as u')->join('class_groups as g', 'g.id', '=', 'u.class_group_id')
            ->where('u.login', $pupil)->where('g.teacher_id', $teacher->user_id)->first(['u.user_id']);
        if (!$row) {
            return null;
        }
        $password = self::readablePassword();
        DB::table('users')->where('user_id', $row->user_id)
            ->update(['password' => Hash::make($password), 'api_token' => null, 'remember_token' => null, 'updated_at' => now()]);
        return $password;
    }

    /** A password a pupil can copy from a printed list: no 0/O, 1/l/I */
    public static function readablePassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return substr($out, 0, 5) . '-' . substr($out, 5);
    }
}
