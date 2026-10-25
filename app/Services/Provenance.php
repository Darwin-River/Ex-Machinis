<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Where code comes from (#324 step 16). A script is compared by its
 * normalised source, comments and spacing removed, so a pasted copy matches
 * however it is laid out; each word (": name ... ;") is compared the same
 * way on its own.
 */
class Provenance
{
    /** The script without comments, one space between words */
    public static function normalise(string $script): string
    {
        $text = preg_replace('/(^|\s)\\\\(\s.*)?$/m', '$1', $script);   // \ to the end of the line
        $text = preg_replace('/(^|\s)\((\s[^)]*)?\)/', '$1', $text);     // ( ... )
        $tokens = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', $tokens);
    }

    public static function hash(string $script): string
    {
        return hash('sha256', self::normalise($script));
    }

    /**
     * The words a script defines, normalised: name => ": name ... ;" (a word
     * defined twice keeps its last definition)
     */
    public static function words(string $script): array
    {
        $tokens = explode(' ', self::normalise($script));
        $words = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i] !== ':' || !isset($tokens[$i + 1])) {
                continue;
            }
            $name = $tokens[$i + 1];
            $end = array_search(';', array_slice($tokens, $i + 2, null, true), true);
            if ($end === false) {
                break;
            }
            $words[$name] = implode(' ', array_slice($tokens, $i, $end - $i + 1));
            $i = $end;
        }
        return $words;
    }

    /**
     * A word's definition as written in the script (comments kept), for
     * "Copy this word": from its ":" to its ";"
     */
    public static function wordSource(string $script, string $name): ?string
    {
        $pattern = '/(^|\s)(:\s+' . preg_quote($name, '/') . '(\s.*?)?\s;)(\s|$)/s';
        if (preg_match_all($pattern, $script, $m) === 0) {
            return null;
        }
        return end($m[2]);
    }

    /**
     * Records a release: its source and hashes, what it was copied from,
     * and the earliest release by another player with the same source
     */
    public static function record(int $agentId, int $companyId, int $userId, string $script,
                                  ?int $copiedFrom = null, ?string $copiedWord = null): int
    {
        $hash = self::hash($script);
        $sameAs = DB::table('releases')->where('source_hash', $hash)->where('user_id', '<>', $userId)
            ->orderBy('id')->value('id');
        $id = DB::table('releases')->insertGetId([
            'agent_id' => $agentId, 'company_id' => $companyId, 'user_id' => $userId,
            'source' => $script, 'source_hash' => $hash,
            'copied_from' => $copiedFrom, 'copied_word' => $copiedWord, 'same_as' => $sameAs,
        ]);
        foreach (self::words($script) as $name => $definition) {
            DB::table('release_words')->insert([
                'release_id' => $id, 'name' => mb_substr($name, 0, 64), 'word_hash' => hash('sha256', $definition),
            ]);
        }
        return $id;
    }

    /**
     * The release a drone's code comes from: its own, or (for a clone) the
     * one its line of code_from drones goes back to
     */
    public static function releaseOf(int $agentId): ?object
    {
        for ($hops = 0; $agentId && $hops < 50; $hops++) {
            $release = DB::table('releases')->where('agent_id', $agentId)->orderBy('id', 'desc')->first();
            if ($release) {
                return $release;
            }
            $agentId = (int) DB::table('agents')->where('agent_id', $agentId)->value('code_from');
        }
        return null;
    }

    /**
     * For each word of a release: where it first appeared, when that was in
     * another release (name => that release's drone)
     */
    public static function wordOrigins(object $release): array
    {
        $origins = [];
        foreach (DB::table('release_words')->where('release_id', $release->id)->get() as $word) {
            $first = DB::table('release_words as w')->join('releases as r', 'r.id', '=', 'w.release_id')
                ->where('w.word_hash', $word->word_hash)->where('r.id', '<', $release->id)
                ->where('r.user_id', '<>', $release->user_id)
                ->orderBy('r.id')->value('r.agent_id');
            $origins[$word->name] = $first ? (int) $first : null;
        }
        return $origins;
    }
}
