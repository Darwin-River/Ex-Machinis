<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The menu bar's emote button (#1062): how a player or visitor feels about
 * the game, with an optional comment and the game time, logged in
 * player_emotes for analysis.
 */
class Emotes
{
    /** The emotes a player can choose, key => [emoji, label], in the pop-up's order */
    public const CHOICES = [
        'excited' => ['🤩', 'Excited'],
        'happy' => ['😄', 'Happy'],
        'content' => ['🙂', 'Content'],
        'curious' => ['🤔', 'Curious'],
        'meh' => ['😐', 'Meh'],
        'confused' => ['😕', 'Confused'],
        'overwhelmed' => ['🤯', 'Overwhelmed'],
        'bored' => ['😴', 'Bored'],
        'sad' => ['😢', 'Sad'],
        'frustrated' => ['😠', 'Frustrated'],
    ];

    /** The button's face before anything is submitted */
    public const DEFAULT = 'content';

    public const COMMENT_MAX = 1000;

    /** The emoji for a key, or the default's */
    public static function emoji(?string $key): string
    {
        return (self::CHOICES[$key] ?? self::CHOICES[self::DEFAULT])[0];
    }

    /** A logged-in player's last emote key, or null */
    public static function lastOf(int $userId): ?string
    {
        $key = DB::table('player_emotes')->where('user_id', $userId)->orderByDesc('id')->value('emote');
        return isset(self::CHOICES[$key]) ? $key : null;
    }

    /** Log an emote; returns its row id */
    public static function log(string $key, ?string $comment, ?\App\User $player, ?string $visitor): int
    {
        $comment = $comment === null ? null : trim($comment);
        return (int) DB::table('player_emotes')->insertGetId([
            'user_id' => $player ? (int) $player->user_id : null,
            'player_name' => $player ? $player->login : null,
            'visitor' => $player ? null : $visitor,
            'emote' => $key,
            'emoji' => self::emoji($key),
            'comment' => $comment === '' ? null : $comment,
            'game_t' => World::gameNow(),
            'created_at' => now(),
        ]);
    }
}
