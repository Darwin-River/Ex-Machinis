<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts between companies (#928), part 7 of the revised system (#921).
 *
 * - A matured company's code posts one (post_contract(resource, units,
 *   price, due_in, deposit)): the price of all of it is held in escrow from
 *   its credits, and it is delivered where the posting drone is.
 * - Another matured company takes it (take_contract(id)), putting up the
 *   deposit; then only it may fill it, with deliver(id, n) there.
 * - Settled on the website's tick, on the due date or once all of it is
 *   delivered: what was delivered is paid from the escrow; for the rest the
 *   buyer gets its credits back and that share of the deposit, and the
 *   seller the rest of its deposit.
 * - cancel_contract(id): the buyer withdraws one nobody took (its escrow
 *   comes back); the seller gives up one it took, settled as if due now.
 * - Escrows and deposits are credits held, not destroyed (CreditCheck).
 */
class Contracts
{
    private static function available(): bool
    {
        return Schema::hasTable('contracts');
    }

    /** The tick: the code's cancels, then contracts due or fully delivered. How many it settled */
    public static function settle(): int
    {
        if (!self::available()) {
            return 0;
        }
        $n = 0;
        $now = World::gameNow();
        foreach (DB::table('contract_actions')->whereNull('done_t')->orderBy('id')->limit(100)->get() as $a) {
            $outcome = DB::transaction(function () use ($a) {
                $c = DB::table('contracts')->where('id', $a->contract_id)->whereNull('settled_t')->lockForUpdate()->first();
                if (!$c) {
                    return 'nothing';
                }
                if ($c->seller_company === null && (int) $c->buyer_company === (int) $a->company_id) {
                    self::close($c, 'cancelled');
                    return 'cancelled';
                }
                if ((int) $c->seller_company === (int) $a->company_id) {
                    self::close($c, null);
                    return 'given_up';
                }
                return 'nothing';
            });
            DB::table('contract_actions')->where('id', $a->id)->update(['done_t' => $now, 'outcome' => $outcome]);
            $n++;
        }
        $ended = DB::table('companies')->whereNotNull('eliminated_at')->select('company_id');
        $due = DB::table('contracts')->whereNull('settled_t')
            ->where(fn ($q) => $q->where('due_t', '<=', $now)->orWhereColumn('delivered', '>=', 'units')
                // a company that ended in the engine (broke, or no drones left)
                ->orWhereIn('buyer_company', $ended)->orWhereIn('seller_company', $ended))
            ->orderBy('id')->limit(200)->pluck('id');
        foreach ($due as $id) {
            DB::transaction(function () use ($id) {
                $c = DB::table('contracts')->where('id', $id)->whereNull('settled_t')->lockForUpdate()->first();
                if ($c) {
                    self::close($c, $c->seller_company === null ? 'expired' : null);
                }
            });
            $n++;
        }
        return $n;
    }

    /**
     * Settles one contract: the delivered units paid from the escrow, the
     * rest of the escrow back to the buyer with that share of the deposit,
     * the rest of the deposit back to the seller. $outcome: expired or
     * cancelled for one nobody took; null works it out (kept or broken)
     */
    private static function close(object $c, ?string $outcome): void
    {
        $units = max(1, (int) $c->units);
        $delivered = min($units, (int) $c->delivered);
        $paid = $delivered * (int) $c->price;
        $refund = max(0, (int) $c->escrow - $paid);
        $deposit = (int) $c->deposit_held;
        // The deposit's share for what wasn't delivered goes to the buyer
        $forfeit = $c->seller_company === null ? 0 : intdiv($deposit * ($units - $delivered), $units);
        $buyerGets = $refund + $forfeit;
        $sellerGets = $c->seller_company === null ? 0 : $paid + $deposit - $forfeit;
        if ($buyerGets > 0) {
            DB::table('companies')->where('company_id', $c->buyer_company)->update(['credits' => DB::raw('credits + ' . $buyerGets)]);
        }
        if ($sellerGets > 0) {
            DB::table('companies')->where('company_id', $c->seller_company)->update(['credits' => DB::raw('credits + ' . $sellerGets)]);
        }
        DB::table('contracts')->where('id', $c->id)->update(['escrow' => 0, 'deposit_held' => 0, 'settled_t' => World::gameNow(),
            'outcome' => $outcome ?? ($delivered >= $units ? 'kept' : 'broken')]);
    }

    /** A company ends (#925): its open contracts settle now, as if due */
    public static function onEnding(int $company): void
    {
        if (!self::available()) {
            return;
        }
        foreach (DB::table('contracts')->whereNull('settled_t')
                     ->where(fn ($q) => $q->where('buyer_company', $company)->orWhere('seller_company', $company))
                     ->lockForUpdate()->get() as $c) {
            self::close($c, $c->seller_company === null ? 'cancelled' : null);
        }
    }

    /** A company's contracts, newest first: {id, side (posted or took), resource, name, units, delivered, price, deposit, due, outcome} */
    public static function ofCompany(int $company): array
    {
        if (!self::available()) {
            return [];
        }
        $names = DB::table('resources')->pluck('name', 'id');
        return DB::table('contracts')->where('buyer_company', $company)->orWhere('seller_company', $company)
            ->orderByDesc('id')->limit(30)->get()->map(fn ($c) => [
                'id' => (int) $c->id, 'side' => (int) $c->buyer_company === $company ? 'posted' : 'took',
                'resource' => (int) $c->resource, 'name' => $names[$c->resource] ?? (string) $c->resource,
                'units' => (int) $c->units, 'delivered' => (int) $c->delivered, 'price' => (int) $c->price,
                'deposit' => (int) $c->deposit, 'due' => DronePage::when((int) $c->due_t),
                'taken' => $c->seller_company !== null, 'outcome' => $c->outcome,
            ])->all();
    }

    /** A company's record as a seller: [kept, broken] */
    public static function record(int $company): array
    {
        if (!self::available()) {
            return [0, 0];
        }
        $rows = DB::table('contracts')->where('seller_company', $company)->whereIn('outcome', ['kept', 'broken'])
            ->groupBy('outcome')->pluck(DB::raw('COUNT(*)'), 'outcome');
        return [(int) ($rows['kept'] ?? 0), (int) ($rows['broken'] ?? 0)];
    }
}
