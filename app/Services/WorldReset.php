<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The reset for the new rules (#642, step 7 of #635). It ends the world the
 * playtests made and opens a fresh one, keeping the old one browsable in
 * place:
 *
 * - every company but the House's ends, with end_cause "reset", and keeps
 *   its rows: its page, its history, its code lineage, the lineage map and
 *   the at-age boards still show it;
 * - its drones become wrecks (wreck_cause "reset") that nobody can plunder,
 *   with nothing aboard, no standing orders and no work queued;
 * - the old share market's open orders, offerings and requests go, and so
 *   do the players' shares, orders and bank payments in ended companies;
 * - the launch queue empties, the startups' daily purchases are forgotten
 *   and every deposit is full again;
 * - player accounts stay, with no credits and their starting share credits
 *   back (a verified account's, or a pupil's), or are deleted with
 *   $deleteAccounts;
 * - the bank then owns a few matured companies running the starter scripts,
 *   so it has shares to sell on day one.
 *
 * The House's station drones and their stock are untouched. Run it with the
 * engines stopped (documents/hosting.md, "Reset the world").
 */
class WorldReset
{
    /** The starters the bank's companies run, in turn */
    public const BANK_STARTERS = ['miner', 'refiner', 'maker'];

    /** What a reset would change now */
    public static function plan(): array
    {
        $old = self::oldCompanies();
        return [
            'companies' => $old->count(),
            'drones' => DB::table('agents')->whereIn('company_id', $old)->whereNull('wrecked_at')->count(),
            'wrecks' => DB::table('agents')->whereIn('company_id', $old)->whereNotNull('wrecked_at')->whereNull('plundered_at')->count(),
            'pending_events' => DB::table('events as e')->join('agents as a', 'a.agent_id', '=', 'e.drone')
                ->whereIn('a.company_id', $old)->where('e.outcome', 0)->count(),
            'queued' => DB::table('launch_queue')->whereNull('released_at')->count(),
            'accounts' => DB::table('users')->whereNotNull('login')->count(),
        ];
    }

    /**
     * Resets the world, and starts $bankCompanies matured companies for the
     * bank. Returns the plan it carried out, with the bank's companies.
     */
    public static function run(int $bankCompanies, bool $deleteAccounts = false): array
    {
        $plan = self::plan();
        $now = World::gameNow();
        $old = self::oldCompanies()->all();
        DB::transaction(function () use ($old, $now, $deleteAccounts) {
            foreach (array_chunk($old, 500) as $companies) {
                $drones = DB::table('agents')->whereIn('company_id', $companies)->pluck('agent_id')->all();
                foreach (array_chunk($drones, 500) as $ids) {
                    // No work left: queued events, actions, commands and messages
                    $actions = DB::table('events')->whereIn('drone', $ids)->where('outcome', 0)->distinct()->pluck('action')->all();
                    foreach (array_chunk($actions, 1000) as $a) {
                        DB::table('actions')->whereIn('id', $a)->update(['aborted' => 1]);
                    }
                    DB::table('events')->whereIn('drone', $ids)->where('outcome', 0)->delete();
                    DB::table('commands')->whereIn('agent_id', $ids)->delete();
                    DB::table('latest_orders')->whereIn('drone', $ids)->delete();
                    DB::table('holdings')->whereIn('drone', $ids)->delete();
                    // Wrecks nobody can plunder (plundered by nobody: #654)
                    DB::table('agents')->whereIn('agent_id', $ids)->whereNull('wrecked_at')
                        ->update(['wrecked_at' => $now, 'wreck_cause' => 'reset', 'launched_running' => 0, 'waiting_action' => null]);
                    DB::table('agents')->whereIn('agent_id', $ids)->whereNull('plundered_at')->update(['plundered_at' => $now]);
                }
                DB::table('companies')->whereIn('company_id', $companies)->whereNull('eliminated_at')
                    ->update(['eliminated_at' => $now, 'end_cause' => 'reset', 'broke_since' => null]);
                // The old share market's open business
                DB::table('share_orders')->whereIn('company', $companies)->delete();
                DB::table('share_orders')->whereIn('share_company', $companies)->delete();
                DB::table('share_offerings')->whereIn('company', $companies)->delete();
                DB::table('share_commitments')->whereIn('company', $companies)->delete();
                DB::table('share_requests')->whereIn('company', $companies)->delete();
                // Players' shares in companies that ended
                DB::table('player_shares')->whereIn('company_id', $companies)->delete();
                DB::table('player_share_orders')->whereIn('company_id', $companies)->delete();
                if (\Illuminate\Support\Facades\Schema::hasTable('player_share_offerings')) {   // #780
                    DB::table('player_share_offerings')->whereIn('company_id', $companies)->delete();
                }
                DB::table('bank_shares')->whereIn('company_id', $companies)->delete();
                DB::table('bank_payments')->whereIn('company_id', $companies)->whereNull('paid_t')->delete();
            }
            DB::table('launch_queue')->whereNull('released_at')->delete();
            DB::table('startup_buys')->delete();
            // Every deposit full again
            DB::statement('UPDATE deposit_state AS s JOIN deposits AS d ON d.object_id = s.object_id AND d.resource = s.resource
                SET s.amount = d.capacity, s.refilled_t = ?', [$now]);

            $players = DB::table('users')->whereNotNull('login');
            if ($deleteAccounts) {
                $ids = $players->pluck('user_id')->all();
                DB::table('player_shares')->whereIn('user_id', $ids)->delete();
                DB::table('player_share_orders')->whereIn('user_id', $ids)->delete();
                DB::table('bank_payments')->whereIn('user_id', $ids)->delete();
                DB::table('player_dividends')->whereIn('user_id', $ids)->delete();
                DB::table('player_wealth_months')->whereIn('user_id', $ids)->delete();
                DB::table('class_groups')->whereIn('teacher_id', $ids)->delete();
                DB::table('users')->whereIn('user_id', $ids)->delete();
            } else {
                // A fresh start: no credits, and the starting share credits back
                // for an account that had them (verified, or a pupil's)
                $grant = (int) config('forge.player_share_credits');
                DB::table('users')->whereNotNull('login')->update([
                    'credits' => 0,
                    'share_credits' => DB::raw("IF(email_verified_at IS NOT NULL OR class_group_id IS NOT NULL, $grant, 0)"),
                    'last_seen_t' => $now,
                    'last_seen_trade' => (int) (DB::table('trades')->max('id') ?? 0),
                ]);
                DB::table('player_wealth_months')->delete();
            }
        });

        $plan['bank_companies'] = self::startBankCompanies($bankCompanies);
        // A fresh world: the credit check starts again from a baseline (#937)
        if (\Illuminate\Support\Facades\Schema::hasTable('credit_checks')) {
            CreditCheck::restart();
        }
        return $plan;
    }

    /**
     * Matured companies the bank owns, running the starter scripts in turn:
     * each starts with the starting credits, owes nothing, and the bank holds
     * its 100 shares. Returns [company id => name]
     */
    public static function startBankCompanies(int $n): array
    {
        $owner = Release::owner();
        $made = [];
        for ($i = 0; $i < $n; $i++) {
            $starter = self::BANK_STARTERS[$i % count(self::BANK_STARTERS)];
            $name = self::freeName('Bank ' . ucfirst($starter));
            $agent = Release::release($owner, $name, (string) Release::starter($starter), null, null, null,
                Release::STARTER_KITS[$starter] ?? 'mining');
            $id = (int) $agent->company_id;
            DB::table('companies')->where('company_id', $id)->update(['startup' => 0, 'owner_id' => $owner->user_id,
                'debt' => 0, 'matured_t' => World::gameNow()]);
            DB::statement('INSERT INTO bank_shares (company_id, units) VALUES (?, ?) ON DUPLICATE KEY UPDATE units = VALUES(units)',
                [$id, Startups::SHARES]);
            $made[$id] = $name;
        }
        return $made;
    }

    /** Every company but the House's, still trading or not */
    private static function oldCompanies(): \Illuminate\Support\Collection
    {
        return DB::table('companies')->where('house', 0)->whereNull('eliminated_at')->pluck('company_id')
            ->merge(DB::table('companies')->where('house', 0)->whereNotNull('eliminated_at')
                ->whereIn('company_id', DB::table('agents')->whereNull('plundered_at')->select('company_id'))->pluck('company_id'))
            ->unique()->values();
    }

    /** "Bank Miner", or "Bank Miner 2" when that is taken */
    private static function freeName(string $base): string
    {
        $name = $base;
        for ($n = 2; DB::table('companies')->where('name', $name)->exists(); $n++) {
            $name = "$base $n";
        }
        return $name;
    }
}
