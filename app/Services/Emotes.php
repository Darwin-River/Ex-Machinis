<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The menu bar's emote button (#1062): how a player or visitor feels about
 * the game, with an optional comment and the game time, logged in
 * player_emotes for analysis. AI players emote through the API (#1068),
 * and the operator reads the log, without names, for the weekly feedback
 * review (DV-06).
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

    /** The sources a reading splits emotes by */
    public const SOURCES = ['people', 'visitors', 'ai'];

    /**
     * The log for the operator's feedback review (DV-06, #1068): emotes
     * written from `since` up to, not including, `until` (dates, Y-m-d UTC;
     * the last 7 days by default), from `source` (people, visitors, ai or
     * all), newest first, at most `limit` (200, up to 1,000). No names:
     * a person or visitor is an opaque `who`, the same each time, and an AI
     * player its persona ID. Returns {since, until, summary, entries}, or
     * {error} for a bad filter.
     */
    public static function reading(\App\User $operator, array $f): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v . ' UTC') !== false;
        foreach (['since', 'until'] as $k) {
            if (isset($f[$k]) && $f[$k] !== '' && !$date($f[$k])) {
                return ['error' => "$k must be a date, like 2026-11-23."];
            }
        }
        $until = !empty($f['until']) ? $f['until'] : gmdate('Y-m-d', time() + 86400);
        $since = !empty($f['since']) ? $f['since'] : gmdate('Y-m-d', strtotime($until . ' UTC') - 7 * 86400);
        $source = $f['source'] ?? 'all';
        if ($source !== 'all' && !in_array($source, self::SOURCES, true)) {
            return ['error' => 'source must be people, visitors, ai or all.'];
        }
        $limit = max(1, min(1000, (int) ($f['limit'] ?? 200)));

        $rows = DB::table('player_emotes as e')->leftJoin('users as u', 'u.user_id', '=', 'e.user_id')
            ->where('e.created_at', '>=', $since . ' 00:00:00')->where('e.created_at', '<', $until . ' 00:00:00')
            ->orderByDesc('e.id')
            ->get(['e.id', 'e.user_id', 'e.visitor', 'e.emote', 'e.emoji', 'e.comment', 'e.game_t', 'e.created_at',
                'u.bot_operator_id', 'u.bot_profile']);

        $summary = [];
        foreach (array_merge(self::SOURCES, ['all']) as $s) {
            $summary[$s] = ['emotes' => 0, 'with_comment' => 0, 'who' => [], 'by_emote' => array_fill_keys(array_keys(self::CHOICES), 0)];
        }
        $entries = [];
        foreach ($rows as $r) {
            $src = $r->user_id === null ? 'visitors' : ($r->bot_operator_id !== null ? 'ai' : 'people');
            $persona = null;
            if ($src === 'ai') {
                $persona = (int) $r->bot_operator_id === (int) $operator->user_id ? CohortFeedback::persona($r->bot_profile) : null;
                $who = $persona ?? 'ai';
            } else {
                $who = substr(hash_hmac('sha256', $r->user_id !== null ? 'u' . $r->user_id : 'v' . $r->visitor, (string) config('app.key')), 0, 10);
            }
            foreach ([$src, 'all'] as $s) {
                $summary[$s]['emotes']++;
                $summary[$s]['with_comment'] += $r->comment !== null ? 1 : 0;
                $summary[$s]['who'][$who] = true;
                if (isset($summary[$s]['by_emote'][$r->emote])) {
                    $summary[$s]['by_emote'][$r->emote]++;
                }
            }
            if (($source === 'all' || $source === $src) && count($entries) < $limit) {
                $entries[] = ['source' => $src, 'who' => $who, 'emote' => $r->emote, 'emoji' => $r->emoji,
                    'comment' => $r->comment, 'game_t' => (int) $r->game_t, 'written' => $r->created_at];
            }
        }
        foreach ($summary as &$s) {
            $s['who'] = count($s['who']);
        }
        unset($s);

        return ['since' => $since, 'until' => $until, 'choices' => array_map(fn ($c) => $c[0], self::CHOICES),
            'summary' => $summary, 'entries' => $entries];
    }
}
