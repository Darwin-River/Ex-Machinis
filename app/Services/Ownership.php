<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who owns a company, and what that allows (#923, #924, #931: part 3 of the
 * revised system, #921).
 *
 * - A company is owned outright when it is a live startup, or a matured
 *   company whose shares one player holds all of (the bank none). Its owner
 *   changes its code at any time, for free. A takeover (buying all of a
 *   public company's shares) makes it owned outright again (#924).
 * - A player owns at most forge.startup_max (5) companies outright,
 *   startups included; a closed startup's slot reopens a game day later.
 *   Public companies have no limit.
 * - Selling any share makes the company public and locks its code (it is no
 *   longer owned outright), and the seller can't buy its shares for
 *   BAN_DAYS real days, so the lock can't be undone by buying straight back.
 * - Its code is seen by its owner (and their teacher) while it is owned
 *   outright; by everyone from the moment its shares are offered, once it
 *   is public, and once it has ended (#931).
 */
class Ownership
{
    /** Real days after selling any of a company's shares that a player can't buy them */
    public const BAN_DAYS = 7;

    /** The player who owns the company outright, or null */
    public static function owner(int|object $company): ?int
    {
        $c = is_object($company) ? $company : DB::table('companies')->where('company_id', $company)->first();
        if (!$c || $c->owner_id === null || $c->eliminated_at !== null || (int) ($c->house ?? 0) === 1) {
            return null;
        }
        if ((int) $c->startup === 1) {
            return (int) $c->owner_id;
        }
        if ($c->matured_t === null) {
            return null;
        }
        return self::soleHolder((int) $c->company_id);
    }

    /** The one player holding all a matured company's shares, or null */
    public static function soleHolder(int $company): ?int
    {
        if ((int) (DB::table('bank_shares')->where('company_id', $company)->value('units') ?? 0) > 0) {
            return null;
        }
        $holders = DB::table('player_shares')->where('company_id', $company)->where('units', '>', 0)->get(['user_id', 'units']);
        return count($holders) === 1 && (int) $holders[0]->units >= PlayerShares::count($company) ? (int) $holders[0]->user_id : null;
    }

    /** Is the company owned outright by this player? */
    public static function ownedBy(int|object $company, int $user): bool
    {
        return self::owner($company) === $user;
    }

    /** The matured companies a player owns outright (their ids) */
    public static function maturedOwned(int $user): array
    {
        $held = DB::table('player_shares AS s')->join('companies AS c', 'c.company_id', '=', 's.company_id')
            ->where('s.user_id', $user)->where('s.units', '>', 0)->whereNull('c.eliminated_at')->where('c.startup', 0)
            ->whereNotNull('c.matured_t')->pluck('s.company_id')->map(fn ($id) => (int) $id)->all();
        return array_values(array_filter($held, fn ($id) => self::soleHolder($id) === $user));
    }

    /** Would buying $units more of a company's shares make this player its sole holder? */
    public static function wouldOwn(int $user, int $company, int $units): bool
    {
        if ((int) (DB::table('bank_shares')->where('company_id', $company)->value('units') ?? 0) > 0) {
            // Buying from the bank: all of its shares and all the others' too
            $others = (int) DB::table('player_shares')->where('company_id', $company)->where('user_id', '<>', $user)->sum('units');
            $bank = (int) DB::table('bank_shares')->where('company_id', $company)->value('units');
            return $others === 0 && $units >= $bank;
        }
        $mine = PlayerShares::held($user, $company);
        return $mine + $units >= PlayerShares::count($company);
    }

    /**
     * May this player buy shares (#1040)? Once they have matured a company
     * of their own, so that they hold shares that earn them money. Players
     * who bought shares before the rule keep investing.
     */
    public static function mayInvest(int $user): bool
    {
        return DB::table('companies')->where('owner_id', $user)->whereNotNull('matured_t')->exists()
            || (Schema::hasTable('player_share_trades') && DB::table('player_share_trades')->where('buyer_id', $user)->exists());
    }

    /** Why this player can't buy shares yet (#1040), or null */
    public static function investProblem(int $user): ?string
    {
        return self::mayInvest($user) ? null
            : 'You can buy shares once you have matured a company of your own: grow a startup to its mark, then claim its shares.';
    }

    /** Why this purchase can't go ahead under the ownership rules, or null */
    public static function buyProblem(int $user, int $company, int $units): ?string
    {
        if ($problem = self::investProblem($user)) {
            return $problem;
        }
        if ($until = self::bannedUntil($user, $company)) {
            return 'You sold shares of this company: you can buy its shares again from ' . $until->format('M j, H:i') . ' UTC ('
                . self::BAN_DAYS . ' real days after selling any).';
        }
        // A class with takeovers off (#1003): no pupil buys every share of a classmate's company
        if (self::wouldOwn($user, $company, $units) && ($problem = ClassSettings::takeoverProblem($user, $company))) {
            return $problem;
        }
        if (self::wouldOwn($user, $company, $units) && Startups::slotsInUse($user) >= (int) config('forge.startup_max')) {
            return 'That would give you all its shares, and you already own ' . (int) config('forge.startup_max')
                . ' companies outright (startups included), the most you may. Sell a share of one, or close one, first.';
        }
        return null;
    }

    /** When this player may buy the company's shares again, or null when they may now */
    public static function bannedUntil(int $user, int $company): ?\Carbon\Carbon
    {
        if (!Schema::hasTable('share_sale_bans')) {
            return null;
        }
        $until = DB::table('share_sale_bans')->where('user_id', $user)->where('company_id', $company)
            ->where('until_at', '>', now())->value('until_at');
        return $until === null ? null : \Carbon\Carbon::parse($until);
    }

    /**
     * A player sold shares of a company: its code is public from now (the
     * IPO), they can't buy its shares for BAN_DAYS real days, and their buy
     * orders for it go
     */
    public static function sold(int $user, int $company): void
    {
        self::offered($company);
        if (Schema::hasTable('share_sale_bans')) {
            DB::statement('INSERT INTO share_sale_bans (user_id, company_id, until_at) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE until_at = VALUES(until_at)', [$user, $company, now()->addDays(self::BAN_DAYS)]);
        }
        DB::table('player_share_orders')->where('user_id', $user)->where('company_id', $company)->where('side', 'buy')->delete();
    }

    /** Its shares were offered (a sell order, or a sale): its code is public from now */
    public static function offered(int $company): void
    {
        if (Schema::hasColumn('companies', 'code_public_t')) {
            DB::table('companies')->where('company_id', $company)->whereNull('code_public_t')
                ->update(['code_public_t' => World::gameNow()]);
        }
    }

    /** Are its owner's shares on offer now (a standing sell order)? Its code can't be pushed while they are */
    public static function onOffer(int $company): bool
    {
        return DB::table('player_share_orders')->where('company_id', $company)->where('side', 'sell')->exists();
    }

    /** Is the company's code public: once its shares were offered, once it is public, and once it has ended? */
    public static function codePublic(int|object $company): bool
    {
        $c = is_object($company) ? $company : DB::table('companies')->where('company_id', $company)->first();
        if (!$c) {
            return false;
        }
        if ($c->eliminated_at !== null || $c->owner_id === null) {
            return true;   // ended, or released before the new rules (all code was public)
        }
        if (($c->code_public_t ?? null) !== null) {
            return true;
        }
        return self::owner($c) === null;
    }

    /** Can this viewer read the company's code: when it is public, its owner, or the owner's teacher */
    public static function canSeeCode(int|object $company, ?User $viewer): bool
    {
        $c = is_object($company) ? $company : DB::table('companies')->where('company_id', $company)->first();
        if (!$c) {
            return false;
        }
        if (self::codePublic($c)) {
            return true;
        }
        if ($viewer === null) {
            return false;
        }
        // Its owner now: the founder of a startup, or a matured company's sole
        // holder (after a takeover, the raider, not the founder)
        $owner = self::owner($c);
        if ($owner === null) {
            return false;
        }
        if ((int) $viewer->user_id === $owner) {
            return true;
        }
        $group = DB::table('users')->where('user_id', $owner)->value('class_group_id');
        return $group !== null && DB::table('class_groups')->where('id', $group)->where('teacher_id', $viewer->user_id)->exists();
    }

    /** What a viewer who can't read the code is told */
    public const PRIVATE_NOTE = 'Its code is private while one player owns it outright. It becomes public once its shares are offered, '
        . 'and when the company ends.';
}
