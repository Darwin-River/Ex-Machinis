<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * A company's code (#594): every drone of a company runs the same script, so
 * the company page shows it, where it came from and each revision (the
 * release, then each code push), and each drone page links there.
 */
class CompanyCode
{
    /**
     * The company's code, or null when it has no release (its program came
     * by email): release, same_as, words, revisions (oldest first, each with
     * number (0 for the release), label, when, fee, percent, drones, script),
     * current (the newest revision)
     */
    public static function of(int $companyId): ?array
    {
        $release = DB::table('releases')->where('company_id', $companyId)->orderBy('id')->first();
        if (!$release) {
            return null;
        }
        $sameAs = $release->same_as ? DB::table('releases')->where('id', $release->same_as)->value('agent_id') : null;
        $revisions = [['number' => 0, 'label' => 'Release', 'when' => 'released ' . \Carbon\Carbon::parse($release->created_at)->format('M j, Y'),
            'fee' => null, 'percent' => null, 'drones' => null, 'script' => $release->source]];
        foreach (DB::table('code_pushes')->where('company_id', $companyId)->orderBy('number')->get() as $p) {
            $revisions[] = ['number' => (int) $p->number, 'label' => 'Push ' . $p->number,
                'when' => DronePage::when((int) $p->game_t), 'fee' => (int) $p->fee, 'percent' => $p->permille / 10,
                'drones' => (int) $p->drones, 'script' => (string) $p->script];
        }
        $current = end($revisions);
        return [
            'release' => $release,
            'same_as' => $sameAs ? (int) $sameAs : null,
            // Where each function first appeared is known for released code only
            'words' => $current['number'] === 0 ? Provenance::wordOrigins($release)
                : array_fill_keys(array_keys(Provenance::words($current['script'])), null),
            'revisions' => $revisions,
            'current' => $current,
        ];
    }

    /** The script of a company's push number n (0: its release), or null */
    public static function script(int $companyId, int $number): ?string
    {
        if ($number === 0) {
            return DB::table('releases')->where('company_id', $companyId)->orderBy('id')->value('source');
        }
        return DB::table('code_pushes')->where('company_id', $companyId)->where('number', $number)->value('script');
    }

    /** A company's newest push: [number, when], or null when it has none */
    public static function latestPush(int $companyId): ?array
    {
        $p = DB::table('code_pushes')->where('company_id', $companyId)->orderByDesc('number')->first(['number', 'game_t']);
        return $p ? ['number' => (int) $p->number, 'when' => DronePage::when((int) $p->game_t)] : null;
    }
}
