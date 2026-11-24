<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A founder's note (#998): a short plain text the founder (owner_id) keeps
 * on a company's page, editable before and after its code is locked, while
 * the company runs. It changes nothing the drones do.
 */
class FounderNote
{
    public const MAX = 280;

    public static function available(): bool
    {
        return Schema::hasColumn('companies', 'founder_note');
    }

    /** The note: {text, when}, or null */
    public static function of(int $company): ?array
    {
        if (!self::available()) {
            return null;
        }
        $c = DB::table('companies')->where('company_id', $company)->first(['founder_note', 'founder_note_t']);
        return $c && $c->founder_note !== null && $c->founder_note !== ''
            ? ['text' => $c->founder_note, 'when' => $c->founder_note_t !== null ? DronePage::when((int) $c->founder_note_t) : null] : null;
    }

    /** Can this player edit the company's note? */
    public static function canEdit(?User $user, object $company): bool
    {
        return $user !== null && $user->login !== null && $company->owner_id !== null
            && (int) $company->owner_id === (int) $user->user_id && $company->eliminated_at === null
            && (int) ($company->house ?? 0) === 0;
    }

    /** Saves (or, empty, clears) the note: {ok, error} */
    public static function save(User $user, int $company, string $text): array
    {
        $c = DB::table('companies')->where('company_id', $company)->first();
        if (!$c || !self::available()) {
            return ['ok' => false, 'error' => 'No such company.'];
        }
        if (!self::canEdit($user, $c)) {
            return ['ok' => false, 'error' => 'Only its founder can write its note, while it runs.'];
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > self::MAX) {
            return ['ok' => false, 'error' => 'A note is at most ' . self::MAX . ' characters.'];
        }
        DB::table('companies')->where('company_id', $company)->update(['founder_note' => $text === '' ? null : $text,
            'founder_note_t' => $text === '' ? null : World::gameNow()]);
        return ['ok' => true, 'error' => null];
    }
}
