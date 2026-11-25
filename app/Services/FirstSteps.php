<?php

namespace App\Services;

use App\User;

/**
 * A first share and a first loan (#1044, part of #1039): one company's card
 * and one button, so a player's first investment and first loan are one
 * small step each. Each card is picked by one rule, printed on its page,
 * that looks only at what companies paid out and what is on offer, never at
 * who owns them.
 */
class FirstSteps
{
    /** Game days of payouts the picks look at */
    public const DAYS = 7;

    /** How many companies a pick tries before it gives up */
    private const TRIES = 10;

    public const SHARE_RULE = 'the public company that paid out the most over the last 7 game days for each credit a share costs now, among those with a share you can buy now.';
    public const LOAN_RULE = 'the open loan request of the public company that paid out the most over the last 7 game days.';

    /**
     * The first share for this player, or null when nothing is on offer:
     * {company, name, price (for 1 share now), paid (credits a share over 7
     * game days), quote (ShareTicket::quote for 1 share)}
     */
    public static function share(?User $user): ?array
    {
        $me = $user ? (int) $user->user_id : null;
        // Not a company the player owns outright: a first share is in someone else's
        $market = collect(PlayerShares::market())->reject(fn ($c) => $me !== null && Ownership::owner($c['company']) === $me);
        if ($market->isEmpty()) {
            return null;
        }
        $paid = PlayerShares::dividendsOverMany($market->pluck('company')->all(), self::DAYS);
        $ranked = $market->map(fn ($c) => $c + ['paid' => (int) ($paid[$c['company']] ?? 0)])
            ->sortByDesc(fn ($c) => [$c['paid'] / max(1, $c['sells_at']), -$c['company']])->values();
        foreach ($ranked->take(self::TRIES) as $c) {
            if ($me !== null && Ownership::bannedUntil($me, $c['company'])) {
                continue;
            }
            $q = ShareTicket::quote($user, $c['company'], 'buy', 1);
            if (($q['ok'] ?? false) && $q['filled'] === 1) {
                return ['company' => $c['company'], 'name' => $c['name'], 'price' => (int) $q['total'],
                    'paid' => $c['paid'], 'count' => (int) $c['count'], 'quote' => $q];
            }
        }
        return null;
    }

    /**
     * The first loan for this player, or null when no request is open:
     * Loans::requests()' fields, and paid (credits its company paid out over
     * 7 game days)
     */
    public static function loan(?User $user): ?array
    {
        $me = $user ? (int) $user->user_id : null;
        $requests = collect(Loans::requests())->reject(fn ($r) => $me !== null && in_array($me, Loans::insiders($r['company']), true));
        if ($requests->isEmpty()) {
            return null;
        }
        $paid = PlayerShares::dividendsOverMany($requests->pluck('company')->unique()->values()->all(), self::DAYS);
        return $requests->map(fn ($r) => $r + ['paid' => (int) ($paid[$r['company']] ?? 0)])
            ->sortByDesc(fn ($r) => [$r['paid'], -$r['id']])->first();
    }
}
