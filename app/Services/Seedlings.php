<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Seedlings (#637, step 2 of the new rules in #635). Every company starts as
 * a seedling: free to start, it is lent its hull and starting credits by the
 * bank, plus a fee, and owes all three (its debt). The engine lets it trade
 * only with the House, buying at most a share of the House's stock of a good
 * at one exchange in a game day, and plunder nothing; its wreck can't be
 * plundered. Its player can reprogram it (the fee goes on its debt) or close
 * it (it leaves the game with all it holds, and its debt is cancelled). Once
 * it holds enough credits to pay its debt, its player may mature it: it
 * repays, becomes a company in its own right, and the player receives its
 * 100 shares. A player has at most forge.seedling_max seedlings at a time;
 * a closed one's slot reopens after forge.seedling_slot_days game days.
 * Anyone may start one as a guest, and claim it into an account with its
 * claim code.
 */
class Seedlings
{
    public const SHARES = PlayerShares::COUNT;   // 100 (#667)

    /** What a new seedling owes: its hull, its starting credits and the fee */
    public static function grant(): int
    {
        return (int) config('forge.seedling_hull_value') + (int) config('forge.starting_credits')
            + (int) config('forge.seedling_fee');
    }

    /** Seedling slots an account is using now: its live seedlings, and those closed within the slot days */
    public static function slotsInUse(int $owner): int
    {
        $since = World::gameNow() - (int) config('forge.seedling_slot_days') * 86400;
        return DB::table('companies')->where('owner_id', $owner)->where('seedling', 1)
            ->where(fn ($q) => $q->whereNull('eliminated_at')->orWhere('eliminated_at', '>', $since))->count();
    }

    /** Why this account can't start or claim a seedling now, or null */
    public static function slotProblem(?User $owner): ?string
    {
        if ($owner === null) {
            return null;
        }
        $max = (int) config('forge.seedling_max');
        return self::slotsInUse((int) $owner->user_id) >= $max
            ? "You have $max seedlings already (a closed one's slot reopens after a game day). Mature or close one first."
            : null;
    }

    /**
     * Starts a seedling for an account, or for a guest (owner null) from an
     * address. Returns ['ok' => true, 'agent' => the drone, 'company' => id,
     * 'claim_code' => a guest's code, shown once] or ['ok' => false,
     * 'status', 'error'].
     */
    public static function start(?User $owner, string $name, string $script, ?string $ip = null): array
    {
        if ($problem = self::slotProblem($owner)) {
            return ['ok' => false, 'status' => 409, 'error' => $problem];
        }
        $guestKey = null;
        if ($owner === null) {
            $key = 'guest-seedling|' . (string) $ip;
            if (RateLimiter::tooManyAttempts($key, max(1, (int) config('forge.seedling_guest_per_day')))) {
                return ['ok' => false, 'status' => 429,
                    'error' => 'Guests from your address have started all the seedlings they may today. Make an account to start more.'];
            }
            RateLimiter::hit($key, 86400);
        }
        $code = $owner === null ? Str::random(24) : null;
        $agent = Release::release(Release::owner(), $name, $script);
        DB::table('companies')->where('company_id', $agent->company_id)->update([
            'seedling' => 1,
            'owner_id' => $owner?->user_id,
            'debt' => self::grant(),
            'guest_key' => $code === null ? null : hash('sha256', $code),
        ]);
        return ['ok' => true, 'agent' => $agent, 'company' => (int) $agent->company_id, 'claim_code' => $code];
    }

    /** A guest's seedling claimed into an account with its claim code */
    public static function claim(User $owner, string $code): array
    {
        $company = DB::table('companies')->where('guest_key', hash('sha256', trim($code)))->where('seedling', 1)
            ->whereNull('owner_id')->first();
        if (!$company || $company->eliminated_at !== null) {
            return ['ok' => false, 'status' => 404, 'error' => 'That code doesn\'t match a guest\'s live seedling.'];
        }
        if ($problem = self::slotProblem($owner)) {
            return ['ok' => false, 'status' => 409, 'error' => $problem];
        }
        DB::table('companies')->where('company_id', $company->company_id)->whereNull('owner_id')
            ->update(['owner_id' => $owner->user_id, 'guest_key' => null]);
        return ['ok' => true, 'company' => (int) $company->company_id, 'name' => $company->name];
    }

    /** The account's seedling, or the reason it can't act on it: [company, null] or [null, [status, error]] */
    private static function own(User $owner, int $companyId): array
    {
        $company = DB::table('companies')->where('company_id', $companyId)->first();
        if (!$company || (int) $company->owner_id !== (int) $owner->user_id) {
            return [null, [404, 'You have no such company.']];
        }
        if ((int) $company->seedling !== 1) {
            return [null, [409, 'That company has matured: it is no longer a seedling.']];
        }
        if ($company->eliminated_at !== null) {
            return [null, [409, 'That seedling has ended.']];
        }
        return [$company, null];
    }

    /** What reprogramming the seedling adds to its debt: the first push is free (#658) */
    public static function pushFee(object $company): int
    {
        return (int) $company->pushes === 0 ? 0 : (int) config('forge.seedling_push_fee');
    }

    /** Reprograms a seedling: its working drones start over on the script, and the fee goes on its debt */
    public static function reprogram(User $owner, int $companyId, string $script): array
    {
        [$company, $error] = self::own($owner, $companyId);
        if ($error) {
            return ['ok' => false, 'status' => $error[0], 'error' => $error[1]];
        }
        if ($problem = Release::scriptProblem($script)) {
            return ['ok' => false, 'status' => 422, 'error' => $problem];
        }
        $drones = DB::table('agents')->where('company_id', $companyId)->whereNull('wrecked_at')
            ->whereNull('station_id')->where('language', 'lua')->orderBy('agent_id')->pluck('agent_id')->all();
        if (!$drones) {
            return ['ok' => false, 'status' => 409, 'error' => 'This seedling has no working drone to reprogram.'];
        }
        $fee = self::pushFee($company);
        return DB::transaction(function () use ($company, $companyId, $script, $drones, $fee) {
            $done = DB::table('companies')->where('company_id', $companyId)->where('pushes', $company->pushes)
                ->update(['debt' => DB::raw('debt + ' . $fee), 'pushes' => DB::raw('pushes + 1')]);
            if (!$done) {
                return ['ok' => false, 'status' => 409, 'error' => 'Another push is under way: try again.'];
            }
            $number = (int) $company->pushes + 1;
            DB::table('code_pushes')->insert(['company_id' => $companyId, 'number' => $number,
                'game_t' => World::gameNow(), 'credits_before' => (int) $company->credits, 'permille' => 0,
                'fee' => $fee, 'drones' => count($drones), 'script' => $script]);
            CodePush::send($drones, $script);
            return ['ok' => true, 'number' => $number, 'fee' => $fee, 'drones' => $drones,
                'debt' => (int) $company->debt + $fee];
        });
    }

    /** Closes a seedling: it leaves the game with all it holds, and its debt is cancelled */
    public static function close(User $owner, int $companyId): array
    {
        [$company, $error] = self::own($owner, $companyId);
        if ($error) {
            return ['ok' => false, 'status' => $error[0], 'error' => $error[1]];
        }
        $now = World::gameNow();
        DB::transaction(function () use ($companyId, $now) {
            DB::table('agents')->where('company_id', $companyId)->whereNull('wrecked_at')
                ->update(['wrecked_at' => $now, 'wreck_cause' => 'closed', 'launched_running' => 0]);
            // Its wrecks hold nothing anyone can take, and show an empty hold (#666)
            DB::table('agents')->where('company_id', $companyId)->whereNull('plundered_at')->update(['plundered_at' => $now]);
            DB::table('holdings')->whereIn('drone', DB::table('agents')->where('company_id', $companyId)->select('agent_id'))->delete();
            DB::table('latest_orders')->whereIn('drone', DB::table('agents')->where('company_id', $companyId)->select('agent_id'))->delete();
            DB::table('companies')->where('company_id', $companyId)->update(['credits' => 0, 'debt' => 0,
                'eliminated_at' => $now, 'end_cause' => 'closed', 'broke_since' => null]);
        });
        return ['ok' => true, 'company' => $companyId, 'name' => $company->name];
    }

    /** Credits a seedling must hold above its debt to mature (#664) */
    public static function matureMargin(): int
    {
        return max(0, (int) config('forge.seedling_mature_margin'));
    }

    /** Does the seedling hold its debt and the working margin, less what it owes in processor tax? */
    public static function canMature(object $company): bool
    {
        return (int) $company->credits - (int) $company->compute_debt >= (int) $company->debt + self::matureMargin();
    }

    /** Matures a seedling that holds enough to pay its debt: it repays, and its 100 shares are the player's */
    public static function mature(User $owner, int $companyId): array
    {
        if (!Players::verified($owner)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Verify your e-mail address before you mature a seedling.'];
        }
        [$company, $error] = self::own($owner, $companyId);
        if ($error) {
            return ['ok' => false, 'status' => $error[0], 'error' => $error[1]];
        }
        $debt = (int) $company->debt;
        $margin = self::matureMargin();
        if (!self::canMature($company)) {
            return ['ok' => false, 'status' => 409, 'error' => 'It holds ' . number_format((int) $company->credits)
                . ' credits and owes ' . number_format($debt) . ': it can mature once it holds its debt and '
                . number_format($margin) . ' more to work with (' . number_format($debt + $margin) . ').'];
        }
        return DB::transaction(function () use ($owner, $companyId, $company, $debt, $margin) {
            $done = DB::table('companies')->where('company_id', $companyId)->where('seedling', 1)
                ->where('credits', '>=', $debt + $margin)->update(['credits' => DB::raw('credits - ' . $debt), 'debt' => 0,
                    'seedling' => 0, 'matured_t' => World::gameNow()]);
            if (!$done) {
                return ['ok' => false, 'status' => 409, 'error' => 'It can\'t pay its debt now: try again.'];
            }
            DB::table('player_shares')->insert(['user_id' => $owner->user_id, 'company_id' => $companyId, 'units' => self::SHARES]);
            return ['ok' => true, 'company' => $companyId, 'name' => $company->name, 'repaid' => $debt, 'shares' => self::SHARES];
        });
    }

    /** An account's live seedlings and matured companies, for its page and the API */
    public static function of(User $owner): array
    {
        $rows = DB::table('companies')->where('owner_id', $owner->user_id)
            ->where(fn ($q) => $q->whereNull('eliminated_at')->orWhere('seedling', 0))
            ->orderByDesc('seedling')->orderBy('company_id')->get();
        $drone = fn ($id) => DB::table('agents')->where('company_id', $id)->orderBy('agent_id')->value('agent_id');
        return [
            'seedlings' => $rows->where('seedling', 1)->map(fn ($c) => [
                'company_id' => (int) $c->company_id, 'name' => $c->name, 'drone' => $drone($c->company_id),
                'credits' => (int) $c->credits, 'debt' => (int) $c->debt,
                'can_mature' => self::canMature($c),
            ])->values()->all(),
            'companies' => $rows->where('seedling', 0)->map(fn ($c) => [
                'company_id' => (int) $c->company_id, 'name' => $c->name, 'drone' => $drone($c->company_id),
                'credits' => (int) $c->credits, 'ended' => $c->eliminated_at !== null,
            ])->values()->all(),
            'slots' => ['used' => self::slotsInUse((int) $owner->user_id), 'max' => (int) config('forge.seedling_max')],
            'grant' => self::grant(),
        ];
    }
}
