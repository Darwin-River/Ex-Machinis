<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loans (#928), part 7 of the revised system (#921).
 *
 * - A public company's code asks for a loan: request_loan(amount, rate,
 *   due_in) (the game engine records it in loan_requests). Only a public
 *   company borrows: its code is locked and anyone can read it, which is
 *   the lender's protection.
 * - Players with at least forge.lend_min_credits (5,000) back requests;
 *   each backing is lent to the company at once, and owes the principal
 *   plus the rate (simple interest for the whole loan) on the due date.
 * - Its code can withdraw what isn't backed yet (cancel_request) and repay
 *   early (repay), with the interest due so far.
 * - On the due date the company repays its lenders. If it can't in full,
 *   every credit it receives goes to them, in proportion to what each is
 *   owed, until they are paid; its dividends and payouts stop meanwhile, and
 *   the default is on its record.
 * - A takeover makes its loans due at once; a company that owes can't
 *   close itself; when it ends, its lenders are paid first.
 */
class Loans
{
    /** Credits a player needs to back new loans */
    public static function minCredits(): int
    {
        return (int) config('forge.lend_min_credits', 5000);
    }

    private static function available(): bool
    {
        return Schema::hasTable('loans');
    }

    /** Interest on $principal at $rateBp hundredths of a percent */
    public static function interest(int $principal, int $rateBp): int
    {
        return intdiv($principal * $rateBp + 5000, 10000);
    }

    /** A rate for a whole loan of $days game days as a rate a game year, in percent */
    public static function perYear(int $rateBp, int $days): float
    {
        return round($rateBp / 100 * 365 / max(1, $days), 1);
    }

    /**
     * The open requests, newest first: {id, company, name, amount, backed,
     * left, rate (percent), per_year, term_days, due (when), due_in (game days)}
     */
    public static function requests(?int $company = null): array
    {
        if (!self::available()) {
            return [];
        }
        $now = World::gameNow();
        return DB::table('loan_requests AS r')->join('companies AS c', 'c.company_id', '=', 'r.company_id')
            ->whereNull('r.cancelled_t')->whereColumn('r.backed', '<', 'r.amount')->where('r.due_t', '>', $now)
            ->whereNull('c.eliminated_at')->when($company, fn ($q) => $q->where('r.company_id', $company))
            ->orderByDesc('r.id')->limit(100)
            ->get(['r.*', 'c.name'])->map(fn ($r) => [
                'id' => (int) $r->id, 'company' => (int) $r->company_id, 'name' => $r->name, 'amount' => (int) $r->amount,
                'backed' => (int) $r->backed, 'left' => (int) $r->amount - (int) $r->backed, 'rate' => $r->rate_bp / 100,
                'per_year' => self::perYear((int) $r->rate_bp, intdiv((int) $r->due_t - (int) $r->requested_t, 86400)),
                'term_days' => intdiv((int) $r->due_t - (int) $r->requested_t, 86400),
                'due' => DronePage::when((int) $r->due_t), 'due_in' => (int) ceil(((int) $r->due_t - $now) / 86400),
            ])->all();
    }

    /**
     * A company's loans: {id, request, lender, lender_name, principal, rate,
     * owed (left to repay), repaid, due, closed, defaulted, insider (backed by
     * its founder or one of its 5 biggest shareholders)}
     */
    public static function ofCompany(int $company): array
    {
        if (!self::available()) {
            return [];
        }
        $insiders = self::insiders($company);
        return DB::table('loans AS l')->join('users AS u', 'u.user_id', '=', 'l.user_id')->where('l.company_id', $company)
            ->orderByDesc('l.id')->limit(100)->get(['l.*', 'u.login'])->map(fn ($l) => [
                'id' => (int) $l->id, 'request' => (int) $l->request_id, 'lender' => (int) $l->user_id, 'lender_name' => $l->login,
                'principal' => (int) $l->principal, 'rate' => $l->rate_bp / 100, 'owed' => (int) $l->owed - (int) $l->repaid,
                'repaid' => (int) $l->repaid, 'due' => DronePage::when((int) $l->due_t), 'closed' => $l->closed_t !== null,
                'defaulted' => $l->defaulted_t !== null, 'insider' => in_array((int) $l->user_id, $insiders, true),
            ])->all();
    }

    /** A player's loans: {id, company, name, principal, rate, owed, repaid, due, closed, defaulted} and totals */
    public static function ofUser(int $user): array
    {
        if (!self::available()) {
            return ['loans' => [], 'lent' => 0, 'interest' => 0, 'defaults' => 0, 'outstanding' => 0];
        }
        $rows = DB::table('loans AS l')->join('companies AS c', 'c.company_id', '=', 'l.company_id')->where('l.user_id', $user)
            ->orderByDesc('l.id')->get(['l.*', 'c.name']);
        $loans = $rows->map(fn ($l) => [
            'id' => (int) $l->id, 'company' => (int) $l->company_id, 'name' => $l->name, 'principal' => (int) $l->principal,
            'rate' => $l->rate_bp / 100, 'owed' => (int) $l->owed - (int) $l->repaid, 'repaid' => (int) $l->repaid,
            'due' => DronePage::when((int) $l->due_t), 'closed' => $l->closed_t !== null, 'defaulted' => $l->defaulted_t !== null,
        ])->all();
        return ['loans' => array_slice($loans, 0, 50), 'lent' => (int) $rows->sum('principal'),
            'interest' => (int) $rows->sum(fn ($l) => max(0, (int) $l->repaid - (int) $l->principal)),
            'defaults' => $rows->whereNotNull('defaulted_t')->count(),
            'outstanding' => (int) $rows->whereNull('closed_t')->sum(fn ($l) => (int) $l->owed - (int) $l->repaid)];
    }

    /**
     * The lenders' board (#928): players by what lending has earned them,
     * interest on loans repaid less what defaults cost, over the loans that
     * have closed: {user, name, lent, net, return_percent, loans, defaults}
     */
    public static function leaderboard(int $limit = 10): array
    {
        if (!self::available()) {
            return [];
        }
        return DB::table('loans AS l')->join('users AS u', 'u.user_id', '=', 'l.user_id')->whereNotNull('l.closed_t')
            ->groupBy('l.user_id', 'u.login')
            ->select('l.user_id', 'u.login', DB::raw('SUM(l.principal) AS lent'), DB::raw('SUM(l.repaid - l.principal) AS net'),
                DB::raw('COUNT(*) AS n'), DB::raw('SUM(l.defaulted_t IS NOT NULL) AS defaults'))
            ->orderByDesc('net')->limit($limit)->get()->map(fn ($r) => [
                'user' => (int) $r->user_id, 'name' => $r->login, 'lent' => (int) $r->lent, 'net' => (int) $r->net,
                'return_percent' => (int) $r->lent > 0 ? round(100 * (int) $r->net / (int) $r->lent, 1) : null,
                'loans' => (int) $r->n, 'defaults' => (int) $r->defaults,
            ])->all();
    }

    /** Its founder and its 5 biggest shareholders: whose loans are marked as insider loans */
    public static function insiders(int $company): array
    {
        $founder = DB::table('companies')->where('company_id', $company)->value('owner_id');
        $top = DB::table('player_shares')->where('company_id', $company)->where('units', '>', 0)->orderByDesc('units')
            ->limit(5)->pluck('user_id')->map(fn ($u) => (int) $u)->all();
        return array_values(array_unique(array_merge($founder !== null ? [(int) $founder] : [], $top)));
    }

    /** Is the company in default: a loan past due and not repaid? Its dividends and payouts stop */
    public static function inDefault(int $company): bool
    {
        return self::available() && DB::table('loans')->where('company_id', $company)->whereNull('closed_t')
            ->whereNotNull('defaulted_t')->exists();
    }

    /** Does the company owe its lenders anything? */
    public static function owes(int $company): bool
    {
        return self::available() && DB::table('loans')->where('company_id', $company)->whereNull('closed_t')->exists();
    }

    private static function fail(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }

    /** A player backs a request with $credits: lent to the company at once */
    public static function back(User $user, int $requestId, int $credits): array
    {
        if (!self::available()) {
            return self::fail(404, 'There are no loans yet.');
        }
        if ($credits < 1) {
            return self::fail(422, 'Back it with at least 1 credit.');
        }
        $min = self::minCredits();
        return DB::transaction(function () use ($user, $requestId, $credits, $min) {
            $now = World::gameNow();
            $r = DB::table('loan_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (!$r || $r->cancelled_t !== null || (int) $r->due_t <= $now) {
                return self::fail(404, 'There is no such open loan request.');
            }
            $c = DB::table('companies')->where('company_id', $r->company_id)->first();
            if (!$c || $c->eliminated_at !== null || Ownership::owner($c) !== null || (int) $c->startup === 1) {
                return self::fail(409, 'Only a public company borrows, and this one no longer is.');
            }
            // Loans only within a class (#1003)
            if ($problem = ClassSettings::loanProblem((int) $user->user_id, (int) $r->company_id)) {
                return self::fail(403, $problem);
            }
            $left = (int) $r->amount - (int) $r->backed;
            if ($left <= 0) {
                return self::fail(409, 'It is fully backed.');
            }
            if ($credits > $left) {
                return self::fail(422, 'It still needs ' . number_format($left) . ' credits: back it with no more than that.');
            }
            $have = (int) DB::table('users')->where('user_id', $user->user_id)->lockForUpdate()->value('credits');
            if ($have < $min) {
                return self::fail(403, 'You need at least ' . number_format($min) . ' credits to back new loans; you have '
                    . number_format($have) . '. Loans you have already backed run to their due date.');
            }
            if ($credits > $have) {
                return self::fail(409, 'You have ' . number_format($have) . ' credits.');
            }
            DB::table('users')->where('user_id', $user->user_id)->update(['credits' => DB::raw('credits - ' . $credits)]);
            DB::table('companies')->where('company_id', $r->company_id)->update(['credits' => DB::raw('credits + ' . $credits)]);
            DB::table('loan_requests')->where('id', $requestId)->update(['backed' => DB::raw('backed + ' . $credits)]);
            $owed = $credits + self::interest($credits, (int) $r->rate_bp);
            $id = DB::table('loans')->insertGetId(['request_id' => $requestId, 'company_id' => $r->company_id,
                'user_id' => $user->user_id, 'principal' => $credits, 'rate_bp' => (int) $r->rate_bp, 'lent_t' => $now,
                'due_t' => (int) $r->due_t, 'owed' => $owed, 'repaid' => 0]);
            return ['ok' => true, 'loan' => $id, 'lent' => $credits, 'owed' => $owed, 'due' => DronePage::when((int) $r->due_t)];
        });
    }

    /** Pays a company's lenders up to $budget credits, in proportion to what each is owed; what it paid */
    private static function payLenders(int $company, int $budget, bool $closeAll, ?array $only = null): int
    {
        $loans = DB::table('loans')->where('company_id', $company)->whereNull('closed_t')
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))->lockForUpdate()->orderBy('id')->get();
        $owed = (int) $loans->sum(fn ($l) => (int) $l->owed - (int) $l->repaid);
        if ($owed <= 0 || $loans->isEmpty()) {
            return 0;
        }
        $pay = max(0, min($budget, $owed));
        $now = World::gameNow();
        $paid = 0;
        foreach ($loans as $i => $l) {
            $due = (int) $l->owed - (int) $l->repaid;
            // In proportion, the last takes what rounding left
            $part = $i === $loans->keys()->last() ? $pay - $paid : intdiv(2 * $pay * $due + $owed, 2 * $owed);
            $part = min($due, max(0, $part));
            if ($part > 0) {
                DB::table('users')->where('user_id', $l->user_id)->update(['credits' => DB::raw('credits + ' . $part)]);
                $paid += $part;
            }
            $full = $part >= $due;
            DB::table('loans')->where('id', $l->id)->update(['repaid' => DB::raw('repaid + ' . $part)]
                + ($full || $closeAll ? ['closed_t' => $now] : [])
                + (!$full && $l->defaulted_t === null ? ['defaulted_t' => $now] : []));
        }
        if ($paid > 0) {
            DB::table('companies')->where('company_id', $company)->update(['credits' => DB::raw('credits - ' . $paid)]);
        }
        return $paid;
    }

    /** What a company can pay now: its credits, less what it owes in processor tax */
    private static function spendable(int $company): int
    {
        $c = DB::table('companies')->where('company_id', $company)->lockForUpdate()->first(['credits', 'compute_debt']);
        return $c ? max(0, (int) $c->credits - (int) ($c->compute_debt ?? 0)) : 0;
    }

    /**
     * The tick: the code's cancel_request and repay, then loans due (and those
     * in default, which take every credit their company receives). How many
     * loans or requests it settled
     */
    public static function settle(): int
    {
        if (!self::available()) {
            return 0;
        }
        $n = 0;
        $now = World::gameNow();
        foreach (DB::table('loan_actions')->whereNull('done_t')->orderBy('id')->limit(100)->get() as $a) {
            $outcome = DB::transaction(function () use ($a, $now) {
                if ($a->kind === 'cancel') {
                    return DB::table('loan_requests')->where('id', $a->request_id)->whereNull('cancelled_t')
                        ->update(['cancelled_t' => $now]) ? 'cancelled' : 'nothing';
                }
                // Repay early: principal plus the interest so far, all or nothing
                $loans = DB::table('loans')->where('request_id', $a->request_id)->whereNull('closed_t')->lockForUpdate()->get();
                if ($loans->isEmpty()) {
                    return 'nothing';
                }
                $due = [];
                foreach ($loans as $l) {
                    $term = max(1, (int) $l->due_t - (int) $l->lent_t);
                    $so_far = self::interest((int) $l->principal, (int) $l->rate_bp) * min($term, max(0, $now - (int) $l->lent_t)) / $term;
                    $due[$l->id] = max(0, (int) $l->principal + (int) floor($so_far) - (int) $l->repaid);
                }
                if (array_sum($due) > self::spendable((int) $a->company_id)) {
                    return 'short';
                }
                foreach ($loans as $l) {
                    DB::table('loans')->where('id', $l->id)->update(['owed' => (int) $l->repaid + $due[$l->id]]);
                }
                self::payLenders((int) $a->company_id, array_sum($due), false, array_keys($due));
                DB::table('loan_requests')->where('id', $a->request_id)->whereNull('cancelled_t')->update(['cancelled_t' => $now]);
                return 'repaid';
            });
            DB::table('loan_actions')->where('id', $a->id)->update(['done_t' => $now, 'outcome' => $outcome]);
            $n++;
        }
        // A company that ended in the engine (broke, or no drones left: #928)
        // closes its loans, its lenders paid from whatever it still held
        foreach (DB::table('loans AS l')->join('companies AS c', 'c.company_id', '=', 'l.company_id')->whereNull('l.closed_t')
                     ->whereNotNull('c.eliminated_at')->distinct()->pluck('l.company_id') as $company) {
            DB::transaction(fn () => self::onEnding((int) $company, self::spendable((int) $company)));
            $n++;
        }
        // Due, or in default: every credit the company has goes to its lenders
        foreach (DB::table('loans')->whereNull('closed_t')->where('due_t', '<=', $now)->distinct()->pluck('company_id') as $company) {
            DB::transaction(function () use ($company) {
                $due = DB::table('loans')->where('company_id', $company)->whereNull('closed_t')
                    ->where('due_t', '<=', World::gameNow())->pluck('id')->all();
                self::payLenders((int) $company, self::spendable((int) $company), false, $due);
            });
            $n++;
        }
        return $n;
    }

    /** A takeover (#924): one player now holds every share, so its loans are due at once, and its requests withdrawn */
    public static function takeover(int $company): void
    {
        if (!self::available()) {
            return;
        }
        $now = World::gameNow();
        DB::table('loans')->where('company_id', $company)->whereNull('closed_t')->where('due_t', '>', $now)->update(['due_t' => $now]);
        DB::table('loan_requests')->where('company_id', $company)->whereNull('cancelled_t')->update(['cancelled_t' => $now]);
    }

    /** A company ends (#925): its lenders are paid first, from $credits; what they got. Its loans close */
    public static function onEnding(int $company, int $credits): int
    {
        if (!self::available()) {
            return 0;
        }
        DB::table('loan_requests')->where('company_id', $company)->whereNull('cancelled_t')->update(['cancelled_t' => World::gameNow()]);
        return self::payLenders($company, max(0, $credits), true);
    }
}
