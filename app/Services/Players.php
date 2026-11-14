<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Player accounts (#636, step 1 of the new rules in #635): a name and a
 * password, with no e-mail yet. Each account starts with credits that can
 * only buy shares (config forge.player_share_credits); what it earns later
 * (share sales, dividends) goes to its ordinary credits. A teacher makes a
 * class group of pseudonymous pupils' accounts, and can give a pupil a new
 * password. The game's own owners (the Public Forge owner, the House) are
 * users too, but have no login and can't sign in.
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

    /**
     * A new account, with its starting share credits. Throws
     * InvalidArgumentException with the reason when the name or password
     * won't do.
     */
    public static function create(string $name, string $password, ?int $classGroup = null): User
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($problem = self::nameProblem($name) ?? self::passwordProblem($password)) {
            throw new \InvalidArgumentException($problem);
        }
        $id = DB::table('users')->insertGetId([
            'name' => $name,
            'login' => $name,
            'email' => null,
            'password' => Hash::make($password),
            'credits' => 0,
            'share_credits' => (int) config('forge.player_share_credits'),
            'class_group_id' => $classGroup,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::where('user_id', $id)->firstOrFail();
    }

    /** The account with this name and password, or null */
    public static function authenticate(string $name, string $password): ?User
    {
        $user = User::where('login', trim($name))->first();
        return $user && Hash::check($password, (string) $user->password) ? $user : null;
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
        if ($token === null || $token === '') {
            return null;
        }
        return User::where('api_token', hash('sha256', $token))->whereNotNull('login')->first();
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
                'pupils' => DB::table('users')->where('class_group_id', $g->id)->count()])->all();
        return [
            'name' => $row->login,
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
                self::create($login, $password, $group);
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
    private static function readablePassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return substr($out, 0, 5) . '-' . substr($out, 5);
    }
}
