<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claude players (#1025): accounts Claude runs on the live site, to playtest
 * it and to keep the world alive while it has few human players. An
 * operator (an account named in forge.operators) makes a key on their
 * account page; whoever holds the key makes Claude players through the API.
 * A Claude player is verified without an e-mail address and plays by every
 * rule a person does. Only users.bot_operator_id marks it, which no player
 * can see: its name, pages and API answers are like anyone's.
 */
class Bots
{
    /** The header that carries a key */
    public const HEADER = 'X-Bot-Key';

    /** Is this account an operator? */
    public static function isOperator(?User $user): bool
    {
        if (!$user || $user->login === null) {
            return false;
        }
        foreach ((array) config('forge.operators') as $name) {
            if (strcasecmp($name, (string) $user->login) === 0) {
                return true;
            }
        }
        return false;
    }

    /** A new key for an operator: the key itself, shown once (only its hash is kept), or null */
    public static function makeKey(User $operator): ?string
    {
        if (!self::isOperator($operator)) {
            return null;
        }
        $key = 'exm_' . Str::random(40);
        DB::table('bot_keys')->insert(['operator_id' => $operator->user_id, 'key_hash' => hash('sha256', $key),
            'created_at' => now()]);
        return $key;
    }

    /** Revokes one of an operator's keys; whether it did */
    public static function revokeKey(User $operator, int $id): bool
    {
        return DB::table('bot_keys')->where('id', $id)->where('operator_id', $operator->user_id)
            ->whereNull('revoked_at')->update(['revoked_at' => now()]) > 0;
    }

    /** An operator's keys: [{id, made, revoked}] (never the keys) */
    public static function keys(User $operator): array
    {
        return DB::table('bot_keys')->where('operator_id', $operator->user_id)->orderByDesc('id')->get()
            ->map(fn ($k) => ['id' => (int) $k->id, 'made' => $k->created_at, 'revoked' => $k->revoked_at])->all();
    }

    /** The operator a key belongs to, while the key stands and they are still an operator; or null */
    public static function operatorFor(?string $key): ?User
    {
        if ($key === null || $key === '') {
            return null;
        }
        $row = DB::table('bot_keys')->where('key_hash', hash('sha256', $key))->whereNull('revoked_at')->first();
        $user = $row ? User::where('user_id', $row->operator_id)->first() : null;
        return self::isOperator($user) ? $user : null;
    }

    /** The header that names the Claude player an operator's key acts for (#1030) */
    public const PLAYER_HEADER = 'X-Bot-Player';

    /**
     * The Claude player this request acts as: a good operator key and
     * X-Bot-Player naming one of that operator's players (#1030: the cohort's
     * network passes no Authorization header), or null
     */
    public static function actingPlayer(?\Illuminate\Http\Request $request = null): ?User
    {
        if ($request === null && !app()->bound('request')) {
            return null;
        }
        $request ??= request();
        $name = trim((string) $request->header(self::PLAYER_HEADER, ''));
        if ($name === '' || !($operator = self::operatorFor($request->header(self::HEADER)))) {
            return null;
        }
        return User::where('login', $name)->where('bot_operator_id', $operator->user_id)->whereNotNull('login')->first();
    }

    /** An operator's Claude players: [{name, credits, companies, since}] */
    public static function players(User $operator): array
    {
        return DB::table('users')->where('bot_operator_id', $operator->user_id)->orderBy('user_id')->get()
            ->map(fn ($u) => ['name' => $u->login, 'credits' => (int) $u->credits,
                'companies' => DB::table('companies')->where('owner_id', $u->user_id)->whereNull('eliminated_at')->count(),
                'since' => $u->created_at])->all();
    }

    /** A new Claude player for an operator: {ok, error, name, password, token} */
    public static function make(User $operator, string $name): array
    {
        $max = (int) config('forge.bot_max');
        if (DB::table('users')->where('bot_operator_id', $operator->user_id)->count() >= $max) {
            return ['ok' => false, 'error' => "An operator may have at most $max Claude players."];
        }
        $password = Players::readablePassword() . '-' . Players::readablePassword();
        try {
            $user = Players::create($name, $password, null, null, (int) $operator->user_id);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'error' => null, 'name' => $user->login, 'password' => $password,
            'token' => Players::issueToken($user)];
    }

    /** Longest profile or notes, in characters */
    public const TEXT_MAX = 60000;

    /** One of an operator's Claude players with its profile and notes (DV-04), or null */
    public static function one(User $operator, string $name): ?array
    {
        $u = DB::table('users')->where('login', $name)->where('bot_operator_id', $operator->user_id)->first();
        return $u ? ['name' => $u->login, 'credits' => (int) $u->credits,
            'companies' => DB::table('companies')->where('owner_id', $u->user_id)->whereNull('eliminated_at')
                ->orderBy('company_id')->get(['company_id', 'name', 'startup'])
                ->map(fn ($c) => ['company_id' => (int) $c->company_id, 'name' => $c->name, 'startup' => (int) $c->startup === 1])->all(),
            'profile' => $u->bot_profile, 'notes' => $u->bot_notes, 'since' => $u->created_at] : null;
    }

    /** Saves a Claude player's profile and/or notes: {ok, error} */
    public static function save(User $operator, string $name, ?string $profile, ?string $notes): array
    {
        $set = [];
        foreach (['bot_profile' => $profile, 'bot_notes' => $notes] as $col => $text) {
            if ($text !== null) {
                if (mb_strlen($text) > self::TEXT_MAX) {
                    return ['ok' => false, 'error' => 'A profile or notes are at most ' . self::TEXT_MAX . ' characters.'];
                }
                $set[$col] = $text;
            }
        }
        $done = $set ? DB::table('users')->where('login', $name)->where('bot_operator_id', $operator->user_id)->update($set) : 0;
        $exists = DB::table('users')->where('login', $name)->where('bot_operator_id', $operator->user_id)->exists();
        return $exists ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'No Claude player of yours has that name.'];
    }

    /** A fresh token for one of an operator's Claude players (the old one stops working), or null */
    public static function token(User $operator, string $name): ?string
    {
        $user = User::where('login', $name)->where('bot_operator_id', $operator->user_id)->first();
        return $user ? Players::issueToken($user) : null;
    }
}
