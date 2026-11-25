<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * The AI player cohort's feedback log (DV-04, #1052): each player's
 * end-of-round report, its experience and recommendations, and the cohort's
 * weekly reviews. A round is an ISO week ("2026-W48"). Only the operator
 * reads and writes it; it is never on GitHub, so it links no persona to an
 * account in public.
 */
class CohortFeedback
{
    public const KINDS = ['report', 'review'];

    /** Longest experience or recommendations, in characters */
    public const TEXT_MAX = 4000;

    /** The round a time falls in: its ISO week, "2026-W48" */
    public static function round(?int $unix = null): string
    {
        return gmdate('o-\WW', $unix ?? time());
    }

    /** A player's persona ID, from the first line of its profile ("# C-03"), or null */
    public static function persona(?string $profile): ?string
    {
        return preg_match('/^#\s*(C-\d+)/m', (string) $profile, $m) ? $m[1] : null;
    }

    /**
     * Saves an entry (one per persona, round and kind: a second replaces the
     * first): {ok, error, entry}. $player null is a cohort review.
     */
    public static function save(User $operator, ?string $player, array $in): array
    {
        $round = (string) ($in['round'] ?? self::round());
        if (!preg_match('/^\d{4}-W\d{2}$/', $round)) {
            return ['ok' => false, 'error' => 'A round is an ISO week, like ' . self::round() . '.'];
        }
        $experience = trim((string) ($in['experience'] ?? ''));
        $recommendations = trim((string) ($in['recommendations'] ?? ''));
        if ($experience === '' || $recommendations === '') {
            return ['ok' => false, 'error' => 'An entry needs its experience and its recommendations.'];
        }
        if (mb_strlen($experience) > self::TEXT_MAX || mb_strlen($recommendations) > self::TEXT_MAX) {
            return ['ok' => false, 'error' => 'Experience and recommendations are at most ' . self::TEXT_MAX . ' characters each.'];
        }
        $ratings = $in['ratings'] ?? null;
        if ($ratings !== null) {
            if (!is_array($ratings) || array_filter($ratings, fn ($v, $k) => !is_string($k) || !is_numeric($v) || $v < 0 || $v > 10, ARRAY_FILTER_USE_BOTH)) {
                return ['ok' => false, 'error' => 'Ratings are {name: 0 to 10}.'];
            }
            $ratings = json_encode(array_map(fn ($v) => $v + 0, $ratings));
        }

        if ($player === null) {
            [$userId, $persona, $kind] = [null, 'cohort', 'review'];
        } else {
            $u = DB::table('users')->where('login', $player)->where('bot_operator_id', $operator->user_id)->first();
            if (!$u) {
                return ['ok' => false, 'error' => 'No Claude player of yours has that name.'];
            }
            $persona = self::persona($u->bot_profile);
            if ($persona === null) {
                return ['ok' => false, 'error' => 'Its profile has no persona ID on its first line ("# C-01").'];
            }
            [$userId, $kind] = [(int) $u->user_id, 'report'];
        }
        $key = ['operator_id' => $operator->user_id, 'persona' => $persona, 'round' => $round, 'kind' => $kind];
        $row = ['user_id' => $userId, 'experience' => $experience, 'recommendations' => $recommendations,
            'ratings' => $ratings, 'updated_at' => now()];
        if (DB::table('bot_feedback')->where($key)->exists()) {
            DB::table('bot_feedback')->where($key)->update($row);
        } else {
            DB::table('bot_feedback')->insert($key + $row + ['created_at' => now()]);
        }
        return ['ok' => true, 'error' => null, 'entry' => self::search($operator, ['persona' => $persona, 'round' => $round, 'kind' => $kind])[0] ?? null];
    }

    /**
     * The operator's entries, newest round first: filters q (words found in
     * the experience or recommendations, every word), persona, round, kind;
     * at most limit (50, up to 500)
     */
    public static function search(User $operator, array $f = []): array
    {
        $q = DB::table('bot_feedback')->where('operator_id', $operator->user_id);
        foreach (['persona', 'round', 'kind'] as $col) {
            if (($f[$col] ?? '') !== '') {
                $q->where($col, (string) $f[$col]);
            }
        }
        foreach (preg_split('/\s+/', trim((string) ($f['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('experience', 'like', $like)->orWhere('recommendations', 'like', $like));
        }
        $limit = max(1, min(500, (int) ($f['limit'] ?? 50)));
        return $q->orderByDesc('round')->orderBy('persona')->limit($limit)->get()
            ->map(fn ($r) => ['persona' => $r->persona, 'round' => $r->round, 'kind' => $r->kind,
                'experience' => $r->experience, 'recommendations' => $r->recommendations,
                'ratings' => $r->ratings !== null ? json_decode($r->ratings, true) : null,
                'written' => (string) ($r->updated_at ?? $r->created_at)])->all();
    }
}
