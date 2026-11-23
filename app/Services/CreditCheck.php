<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Do credits balance? (#937) Once a game day: what every holder has
 * (companies, accounts, the old share market's commitments), and the flows
 * since the last check (credits created or destroyed: CreditFlows and the
 * triggers of migration 0094), read in one consistent snapshot. The change
 * in what is held less the flows should be 0; anything else is a bug that
 * created or destroyed credits, logged as a warning and shown by the clock
 * API and the economy dashboard. The first check, and the first after a
 * world reset, is a baseline.
 */
class CreditCheck
{
    /** Checks kept, besides every one that found a difference */
    public const KEEP_CHECKS = 2000;

    /** Counted flows are kept for the latest this many checks, to trace a difference */
    public const KEEP_FLOW_CHECKS = 50;

    /** Checks the status reads back */
    public const RECENT = 100;

    private static function day(int $t): int
    {
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        return intdiv($t - $epoch, 86400);
    }

    /** Checks once a game day: returns the check, or null when today's is done (or another tick is checking) */
    public static function checkIfDue(): ?array
    {
        $day = self::day(World::gameNow());
        $last = DB::table('credit_checks')->orderByDesc('id')->value('day');
        if ($last !== null && (int) $last >= $day) {
            return null;
        }
        $lock = Cache::lock('credit-check', 60);
        if (!$lock->get()) {
            return null;
        }
        try {
            return self::check();
        } finally {
            $lock->release();
        }
    }

    /** Checks now */
    public static function check(): array
    {
        $now = World::gameNow();
        $day = self::day($now);

        // What is held and the flows not yet counted, in one snapshot: a
        // flow is written in the same transaction as its change, so both or
        // neither are seen
        if (DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        DB::beginTransaction();
        try {
            $held = (int) DB::selectOne('SELECT
                (SELECT IFNULL(SUM(credits), 0) FROM companies)
                + (SELECT IFNULL(SUM(IFNULL(credits, 0) + share_credits), 0) FROM users)
                + (SELECT IFNULL(SUM(credits), 0) FROM share_commitments) AS held')->held;
            $flows = DB::table('credit_flows')->whereNull('check_id')->get(['id', 'kind', 'amount']);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $prev = DB::table('credit_checks')->orderByDesc('id')->first(['held']);
        $kinds = [];
        $created = 0;
        $destroyed = 0;
        foreach ($flows as $f) {
            $a = (int) $f->amount;
            $kinds[$f->kind] = ($kinds[$f->kind] ?? 0) + $a;
            $a > 0 ? $created += $a : $destroyed -= $a;
        }
        ksort($kinds);
        $sum = $created - $destroyed;
        $change = $prev ? $held - (int) $prev->held : null;
        $difference = $prev ? $change - $sum : null;

        $row = ['game_t' => $now, 'day' => $day, 'held' => $held, 'change' => $change, 'flows' => $sum,
            'difference' => $difference, 'created' => $created, 'destroyed' => $destroyed,
            'kinds' => $kinds ? json_encode($kinds) : null];
        $id = DB::table('credit_checks')->insertGetId($row);
        foreach (array_chunk($flows->pluck('id')->all(), 1000) as $ids) {
            DB::table('credit_flows')->whereIn('id', $ids)->update(['check_id' => $id]);
        }
        if ($difference !== null && $difference !== 0) {
            Log::warning('Credits don\'t balance (#937): on game day ' . $day . ' what is held changed by ' . $change
                . ', but the flows came to ' . $sum . ' (a difference of ' . $difference . ')', ['kinds' => $kinds]);
        }
        self::prune($id);
        return ['id' => $id, 'kinds' => $kinds] + $row;
    }

    /** Old counted flows and old checks that balanced */
    private static function prune(int $id): void
    {
        DB::table('credit_flows')->whereNotNull('check_id')->where('check_id', '<=', $id - self::KEEP_FLOW_CHECKS)
            ->limit(20000)->delete();
        DB::table('credit_checks')->where('id', '<=', $id - self::KEEP_CHECKS)
            ->where(fn ($q) => $q->whereNull('difference')->orWhere('difference', 0))->limit(1000)->delete();
    }

    /** A world reset starts the check afresh: its next check is a baseline */
    public static function restart(): void
    {
        DB::table('credit_flows')->delete();
        DB::table('credit_checks')->delete();
    }

    /**
     * The latest check and how many recent ones found a difference, for the
     * clock API and the economy dashboard
     */
    public static function status(): array
    {
        $recent = DB::table('credit_checks')->orderByDesc('id')->limit(self::RECENT)
            ->get(['day', 'game_t', 'held', 'change', 'flows', 'difference', 'created', 'destroyed', 'kinds']);
        $last = $recent->first();
        $bad = $recent->filter(fn ($c) => $c->difference !== null && (int) $c->difference !== 0);
        return [
            'checked_day' => $last ? (int) $last->day : null,
            'held' => $last ? (int) $last->held : null,
            'created' => $last ? (int) $last->created : null,
            'destroyed' => $last ? (int) $last->destroyed : null,
            'difference' => $last && $last->difference !== null ? (int) $last->difference : null,
            'kinds' => $last && $last->kinds ? json_decode($last->kinds, true) : [],
            'checks' => $recent->count(),
            'mismatches' => $bad->count(),
            'last_mismatch_day' => $bad->isEmpty() ? null : (int) $bad->first()->day,
            'balanced' => $bad->isEmpty(),
        ];
    }
}
