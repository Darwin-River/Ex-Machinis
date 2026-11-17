<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;

/**
 * The Trading desk (#779: round 72's players traded shares across three
 * pages, and Saoirse's mockup-trader-r72 put it on one): a watch list of
 * matured companies with health pills, one company's book as a ladder with
 * the bank at the top and bottom and the band players trade in, an order
 * ticket that says in words what will happen, the player's shares with
 * their gain including dividends, and their trading script with its log.
 */
class TradingDesk
{
    /**
     * Everything the page shows. $company: the one on the ladder (the
     * player's biggest holding, else the watch list's first, when null)
     */
    public static function build(?User $user, ?int $company): array
    {
        $board = \App\Http\Controllers\WorldController::sharesPage()['playerMarket'] ?? null;
        if ($board === null) {
            return ['board' => null];
        }
        $mine = $user && $user->login !== null ? PlayerShares::of($user) : null;
        $held = [];
        foreach ($mine['holdings'] ?? [] as $h) {
            $held[(int) $h['company_id']] = (int) $h['units'];
        }
        // The watch list: what you hold first, then by the board's order (best return)
        $watch = array_map(fn ($m) => ['company' => (int) $m['company'], 'name' => $m['name'], 'buys_at' => $m['buys_at'],
            'sells_at' => $m['sells_at'], 'yield_percent' => $m['yield_percent'] ?? null, 'return_percent' => $m['return_percent'] ?? null,
            'held' => $held[(int) $m['company']] ?? 0, 'health' => self::health($m), 'note' => $m['health_note'] ?? null,
            'offered' => $m['offered'] ?? 0], $board);
        usort($watch, fn ($a, $b) => [$b['held'] > 0, $b['held']] <=> [$a['held'] > 0, $a['held']]);

        $ids = array_column($watch, 'company');
        if ($company === null || !in_array($company, $ids, true)) {
            $company = $ids[0] ?? null;
        }
        $ladder = $company !== null ? self::ladder($company) : null;
        return ['board' => $board, 'watch' => $watch, 'company' => $company, 'ladder' => $ladder,
            'me' => $mine === null ? null : [
                'name' => $user->login,
                'credits' => (int) DB::table('users')->where('user_id', $user->user_id)->value('credits'),
                'share_credits' => (int) DB::table('users')->where('user_id', $user->user_id)->value('share_credits'),
                'shares' => self::gains((int) $user->user_id, $mine['holdings']),
                'orders' => $mine['orders'],
                'script' => TradeScripts::of($user),
            ]];
    }

    /** What the order ticket's words are worked out from, in the page (#779) */
    public static function ticket(array $ladder, array $me): array
    {
        return ['asks' => collect($ladder['asks'])->sortBy('price')->values()->all(), 'bids' => $ladder['bids'], 'p' => $ladder['prices'],
            'me' => $me['name'], 'held' => collect($me['shares'])->firstWhere('company_id', $ladder['company'])['units'] ?? 0,
            'credits' => $me['credits'], 'share_credits' => $me['share_credits'], 'name' => $ladder['name'],
            'rebate' => (int) config('forge.rebate_permille') / 10, 'pay_days' => (int) config('forge.bank_pay_days')];
    }

    /** A health pill: [class, text], the worst first, as the stock exchange words it */
    public static function health(array $m): array
    {
        if (($m['dissolves_in'] ?? null) !== null) {
            return ['bad', 'dissolves in ' . $m['dissolves_in'] . ' game days'];
        }
        if (!empty($m['failing'])) {
            return ['bad', 'failing'];
        }
        if (!empty($m['at_risk'])) {
            return ['warn', 'at risk'];
        }
        if (!empty($m['stalled'])) {
            return ['warn', 'stalled'];
        }
        return ['ok', 'steady'];
    }

    /**
     * A company's book as a ladder, dearest at the top: the band's top
     * (high), the bank's selling price, players' sell orders, then their
     * buy orders, the bank's buying price and the band's bottom (low)
     */
    public static function ladder(int $company): array
    {
        $p = PlayerShares::prices($company);
        $book = PlayerShares::book($company);
        $name = (string) DB::table('companies')->where('company_id', $company)->value('name');
        return ['company' => $company, 'name' => $name, 'prices' => $p, 'count' => PlayerShares::count($company),
            'offering' => PlayerShares::offering($company),
            'asks' => array_reverse($book['sell']), 'bids' => $book['buy'],
            'trades' => PlayerShares::trades($company, 8)];
    }

    /**
     * Each holding with what it cost and what it has paid (#779: "your
     * shares, with gain including dividends"): cost is the average price of
     * the shares bought (from players, the bank or an offering) times those
     * held; shares that came at maturity cost nothing. Gain is today's
     * cash-out value less cost, plus the dividends they paid
     */
    public static function gains(int $user, array $holdings): array
    {
        $out = [];
        foreach ($holdings as $h) {
            $c = (int) $h['company_id'];
            $bought = DB::table('player_share_trades')->where('buyer_id', $user)->where('company_id', $c)
                ->selectRaw('COALESCE(SUM(units), 0) AS units, COALESCE(SUM(units * price), 0) AS credits')->first();
            $units = (int) $h['units'];
            $boughtUnits = (int) $bought->units;
            $avg = $boughtUnits > 0 ? (int) $bought->credits / $boughtUnits : 0;
            $paidFor = min($units, $boughtUnits);
            $cost = (int) round($avg * $paidFor);
            $dividends = (int) DB::table('player_dividends')->where('user_id', $user)->where('company_id', $c)->sum('amount');
            $value = $units * (int) $h['cash_out'];
            $out[] = ['company_id' => $c, 'name' => $h['name'], 'units' => $units, 'cash_out' => (int) $h['cash_out'],
                'value' => $value, 'cost' => $cost, 'founder_units' => $units - $paidFor, 'dividends' => $dividends,
                'gain' => $value - $cost + $dividends,
                'gain_percent' => $cost > 0 ? round(100 * ($value - $cost + $dividends) / $cost, 1) : null];
        }
        return $out;
    }
}
