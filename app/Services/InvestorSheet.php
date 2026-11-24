<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The investor sheet (#994, part 11 of #921): on a matured company's page,
 * what a player weighs before buying its shares or backing its loans, in one
 * place, from data the game already keeps:
 * - its price against its close-out value, and its idle credits;
 * - what it pays out: the dividend share its code declares against what it
 *   paid over 30 game days, and its pay_out payouts, asked against paid;
 * - what it owes: open loans (insider loans marked), loan requests and
 *   contracts, with due dates;
 * - its dealings with companies of its founder or its 5 biggest holders;
 * - its founder's other companies, how each ended, and their defaults.
 */
class InvestorSheet
{
    /** Shareholders counted as insiders, biggest first */
    public const INSIDERS = 5;

    public static function of(int $company): ?array
    {
        $c = DB::table('companies')->where('company_id', $company)->first();
        if (!$c || (int) ($c->startup ?? 0) === 1 || ($c->matured_t ?? null) === null || (int) ($c->house ?? 0) === 1) {
            return null;
        }
        $now = World::gameNow();
        $founder = $c->owner_id !== null ? (int) $c->owner_id : null;
        $insiders = self::insiders($company, $founder);

        // Price and value
        $prices = PlayerShares::prices($company);
        $cashOut = (int) $prices['buys_at'];
        // The last trade between two players, not the bank's
        $last = DB::table('player_share_trades')->where('company_id', $company)->whereNotNull('seller_id')->whereNotNull('buyer_id')
            ->orderByDesc('id')->first(['price', 'game_t']);
        $lastPrice = $last ? (int) $last->price : null;
        $value = $cashOut * PlayerShares::COUNT;
        $credits = (int) $c->credits;

        // What it pays out
        $paid30 = PlayerShares::dividends30($company) * PlayerShares::COUNT;
        $payouts = Schema::hasTable('company_payouts') ? DB::table('company_payouts')->where('company_id', $company)
            ->where('requested_t', '>=', $now - 30 * 86400)->get(['amount', 'paid', 'paid_t']) : collect();

        // What it owes
        // Loans::ofCompany marks insider loans (its founder or biggest holders lent)
        $loans = collect(Loans::ofCompany($company))->where('closed', false)->values()->all();
        $requests = Loans::requests($company);
        $contracts = collect(Contracts::ofCompany($company))->whereNull('outcome')->values()->all();

        // Dealings with insiders' other companies
        $related = collect(Dealings::of($company, 50))->filter(fn ($d) => in_array(
            (int) (DB::table('companies')->where('company_id', $d['company_id'])->value('owner_id') ?? 0), array_keys($insiders), true))
            ->values()->all();

        return [
            'price' => ['last' => $lastPrice, 'last_when' => $last ? DronePage::when((int) $last->game_t) : null,
                'cash_out' => $cashOut, 'premium_percent' => $lastPrice !== null && $cashOut > 0 ? round(100 * ($lastPrice - $cashOut) / $cashOut) : null],
            'idle' => ['credits' => $credits, 'percent_of_value' => $value > 0 ? min(100, round(100 * $credits / $value)) : null],
            'payout' => ['declared_percent' => PlayerShares::dividendPermille($company) / 10, 'dividends_paid_30' => $paid30,
                'payouts_asked_30' => (int) $payouts->sum('amount'), 'payouts_paid_30' => (int) $payouts->sum('paid'),
                'payouts_pending' => $payouts->whereNull('paid_t')->count()],
            'loans' => $loans, 'requests' => $requests, 'contracts' => $contracts,
            'insiders' => array_values($insiders),
            'related' => $related,
            'founder' => $founder !== null ? self::founderRecord($founder, $company) : null,
        ];
    }

    /** The founder and the biggest holders: [user_id => {user_id, name, units, founder}] */
    private static function insiders(int $company, ?int $founder): array
    {
        $out = [];
        $rows = DB::table('player_shares as s')->join('users as u', 'u.user_id', '=', 's.user_id')
            ->where('s.company_id', $company)->where('s.units', '>', 0)->whereNotNull('u.login')
            ->orderByDesc('s.units')->orderBy('s.user_id')->limit(self::INSIDERS)->get(['s.user_id', 'u.login', 's.units']);
        foreach ($rows as $r) {
            $out[(int) $r->user_id] = ['user_id' => (int) $r->user_id, 'name' => $r->login, 'units' => (int) $r->units,
                'founder' => (int) $r->user_id === $founder];
        }
        if ($founder !== null && !isset($out[$founder])) {
            $name = DB::table('users')->where('user_id', $founder)->value('login');
            if ($name !== null) {
                $out[$founder] = ['user_id' => $founder, 'name' => $name, 'units' => 0, 'founder' => true];
            }
        }
        return $out;
    }

    /** The founder's other companies: {name, login, companies: [{company_id, name, matured, ended, how, defaults}], ended, defaults} */
    private static function founderRecord(int $founder, int $except): array
    {
        $rows = DB::table('companies')->where('owner_id', $founder)->where('company_id', '<>', $except)
            ->orderByDesc('company_id')->limit(20)->get(['company_id', 'name', 'startup', 'matured_t', 'eliminated_at', 'end_cause']);
        $defaults = Schema::hasTable('loans') && $rows->count()
            ? DB::table('loans')->whereIn('company_id', $rows->pluck('company_id'))->whereNotNull('defaulted_t')
                ->groupBy('company_id')->pluck(DB::raw('COUNT(*)'), 'company_id') : collect();
        $list = $rows->map(fn ($r) => ['company_id' => (int) $r->company_id, 'name' => $r->name,
            'matured' => $r->matured_t !== null, 'ended' => $r->eliminated_at !== null,
            'how' => $r->end_cause && isset(PostMortem::ENDS[$r->end_cause]) ? PostMortem::ENDS[$r->end_cause] : ($r->eliminated_at !== null ? 'Ended' : null),
            'defaults' => (int) ($defaults[$r->company_id] ?? 0)])->all();
        return ['login' => DB::table('users')->where('user_id', $founder)->value('login'), 'companies' => $list,
            'matured' => collect($list)->where('matured', true)->count(), 'ended' => collect($list)->where('ended', true)->count(),
            'defaults' => array_sum(array_column($list, 'defaults'))];
    }
}
