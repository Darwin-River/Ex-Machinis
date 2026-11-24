<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class settings (#1003): on a teacher's class page, takeovers within the
 * class on or off, backing loans only within the class (both on by
 * default), and a read-only log of what passed between the pupils.
 */
class ClassSettings
{
    /** Game days the log covers */
    public const LOG_DAYS = 30;

    public static function available(): bool
    {
        return Schema::hasColumn('class_groups', 'takeovers');
    }

    /** A group's settings: {takeovers, loans_within} */
    public static function of(int $group): array
    {
        $row = self::available() ? DB::table('class_groups')->where('id', $group)->first(['takeovers', 'loans_within']) : null;
        return ['takeovers' => $row ? (bool) $row->takeovers : true, 'loans_within' => $row ? (bool) $row->loans_within : true];
    }

    /** Saves a teacher's settings for one of their groups: false when it isn't theirs */
    public static function save(User $teacher, int $group, bool $takeovers, bool $loansWithin): bool
    {
        if (!self::available()) {
            return false;
        }
        return DB::table('class_groups')->where('id', $group)->where('teacher_id', $teacher->user_id)
            ->update(['takeovers' => $takeovers ? 1 : 0, 'loans_within' => $loansWithin ? 1 : 0]) > 0
            || DB::table('class_groups')->where('id', $group)->where('teacher_id', $teacher->user_id)->exists();
    }

    private static function groupOf(?int $user): ?int
    {
        if ($user === null) {
            return null;
        }
        $g = DB::table('users')->where('user_id', $user)->value('class_group_id');
        return $g !== null ? (int) $g : null;
    }

    /**
     * Why $user may not take over $company, or null: when its founder is a
     * pupil of a group with takeovers off and $user is someone else
     */
    public static function takeoverProblem(int $user, int $company): ?string
    {
        $founder = DB::table('companies')->where('company_id', $company)->value('owner_id');
        if ($founder === null || (int) $founder === $user) {
            return null;
        }
        $group = self::groupOf((int) $founder);
        if ($group === null || self::of($group)['takeovers']) {
            return null;
        }
        return 'Your class doesn\'t allow takeovers: you can\'t buy every share of a company a classmate founded.';
    }

    /**
     * Why $lender may not back a loan to $company, or null: a pupil of a
     * group with loans only within the class backs only classmates'
     * companies, and those are backed only by the class
     */
    public static function loanProblem(int $lender, int $company): ?string
    {
        $founder = DB::table('companies')->where('company_id', $company)->value('owner_id');
        $mine = self::groupOf($lender);
        $theirs = self::groupOf($founder !== null ? (int) $founder : null);
        if ($mine === $theirs) {
            return null;
        }
        if ($mine !== null && self::of($mine)['loans_within']) {
            return 'Your class backs only loans its own pupils\' companies ask for.';
        }
        if ($theirs !== null && self::of($theirs)['loans_within']) {
            return 'That company belongs to a class that borrows only from its own pupils.';
        }
        return null;
    }

    /**
     * What passed between a group's pupils over the last LOG_DAYS game days:
     * {trades, loans, dividends, payouts}, each newest first
     */
    public static function log(int $group): array
    {
        $pupils = DB::table('users')->where('class_group_id', $group)->pluck('login', 'user_id')->all();
        $ids = array_map('intval', array_keys($pupils));
        $out = ['trades' => [], 'loans' => [], 'dividends' => [], 'payouts' => []];
        if (!$ids) {
            return $out;
        }
        $since = World::gameNow() - self::LOG_DAYS * 86400;
        $companies = DB::table('companies')->whereIn('owner_id', $ids)->pluck('name', 'company_id')->all();
        $owner = DB::table('companies')->whereIn('owner_id', $ids)->pluck('owner_id', 'company_id')->all();
        $out['trades'] = DB::table('player_share_trades as t')->join('companies as c', 'c.company_id', '=', 't.company_id')
            ->whereIn('t.seller_id', $ids)->whereIn('t.buyer_id', $ids)->where('t.game_t', '>=', $since)
            ->orderByDesc('t.id')->limit(50)->get(['t.seller_id', 't.buyer_id', 't.units', 't.price', 't.game_t', 'c.name'])
            ->map(fn ($t) => ['when' => DronePage::when((int) $t->game_t), 'seller' => $pupils[$t->seller_id], 'buyer' => $pupils[$t->buyer_id],
                'company' => $t->name, 'units' => (int) $t->units, 'price' => (int) $t->price])->all();
        if (Schema::hasTable('loans') && $companies) {
            $out['loans'] = DB::table('loans')->whereIn('user_id', $ids)->whereIn('company_id', array_keys($companies))
                ->where('lent_t', '>=', $since)->orderByDesc('id')->limit(50)->get()
                ->map(fn ($l) => ['when' => DronePage::when((int) $l->lent_t), 'lender' => $pupils[$l->user_id],
                    'company' => $companies[$l->company_id], 'founder' => $pupils[$owner[$l->company_id]] ?? null,
                    'principal' => (int) $l->principal, 'rate' => $l->rate_bp / 100, 'repaid' => (int) $l->repaid,
                    'closed' => $l->closed_t !== null, 'defaulted' => $l->defaulted_t !== null])->all();
        }
        if ($companies) {
            $today = MarketHistory::today();
            $out['dividends'] = DB::table('player_dividends')->whereIn('user_id', $ids)->whereIn('company_id', array_keys($companies))
                ->where('day', '>=', $today - self::LOG_DAYS)->groupBy('user_id', 'company_id')
                ->get(['user_id', 'company_id', DB::raw('SUM(amount) AS total')])
                ->filter(fn ($d) => (int) ($owner[$d->company_id] ?? 0) !== (int) $d->user_id)   // between pupils, not to its founder
                ->map(fn ($d) => ['pupil' => $pupils[$d->user_id], 'company' => $companies[$d->company_id], 'total' => (int) $d->total])
                ->sortByDesc('total')->values()->all();
            if (Schema::hasTable('company_payouts')) {
                $out['payouts'] = DB::table('company_payouts')->whereIn('company_id', array_keys($companies))->whereNotNull('paid_t')
                    ->where('paid_t', '>=', $since)->orderByDesc('id')->limit(50)->get()
                    ->map(fn ($p) => ['when' => DronePage::when((int) $p->paid_t), 'company' => $companies[$p->company_id],
                        'paid' => (int) $p->paid])->all();
            }
        }
        return $out;
    }
}
