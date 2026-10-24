<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An invitation code: in an invite-only world an account can only be
 * opened with an unused one (#324 step 6).
 */
class Invite extends Model
{
    protected $table = 'invites';
    protected $fillable = ['code', 'note'];
    public $timestamps = false;

    /** Creates a new unused invitation */
    public static function generate(?string $note = null): Invite
    {
        do {
            $code = strtoupper(Str::random(12));
        } while (Invite::where('code', $code)->exists());

        return Invite::create(['code' => $code, 'note' => $note]);
    }

    /** The unused invitation with this code, if any */
    public static function findUnused(?string $code): ?Invite
    {
        if ($code === null || trim($code) === '') {
            return null;
        }
        return Invite::where('code', strtoupper(trim($code)))->whereNull('used_by')->first();
    }

    /** Marks the invitation as used to open this account */
    public function useFor(User $user): void
    {
        $this->used_by = $user->user_id;
        $this->used_at = now();
        $this->save();
    }
}
