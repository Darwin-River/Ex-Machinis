<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The player's path through the game (#987): make an account, code drones,
 * invest, lend. The same four steps on the home page, Getting started, the
 * sign-up page and the account page, each with where this player is on it.
 * Nothing is locked: every step is open to everyone.
 */
class Pipeline
{
    /**
     * The four steps: {key, n, title, text, url, action, done, progress}.
     * For a player, done says whether they have done it, and progress (where
     * there is one) is {now, of, label}. 'next' is the key of the first step
     * not yet done (or null when all are).
     */
    public static function for(?User $user): array
    {
        $player = $user && $user->login !== null ? $user : null;
        $id = $player ? (int) $player->user_id : null;
        $min = Loans::minCredits();
        $grant = Startups::grant();
        $mature = $grant + Startups::matureMargin();

        $credits = $id ? (int) DB::table('users')->where('user_id', $id)->value('credits') : 0;
        $matured = $id ? DB::table('companies')->where('owner_id', $id)->where('startup', 0)->count() : 0;
        // The best startup's credits on the way to maturing
        $best = $id ? (int) DB::table('companies')->where('owner_id', $id)->where('startup', 1)
            ->whereNull('eliminated_at')->max('credits') : 0;
        // Shares in other players' companies
        $invested = $id && Schema::hasTable('player_shares') ? DB::table('player_shares as s')
            ->join('companies as c', 'c.company_id', '=', 's.company_id')
            ->where('s.user_id', $id)->where('s.units', '>', 0)
            ->where(fn ($q) => $q->whereNull('c.owner_id')->orWhere('c.owner_id', '!=', $id))
            ->distinct()->count('s.company_id') : 0;
        $loans = $id && Schema::hasTable('loans') ? DB::table('loans')->where('user_id', $id)->count() : 0;

        $steps = [
            ['key' => 'account', 'title' => 'Make an account',
                'text' => 'Free. It keeps the companies you start and the credits they earn you.',
                'url' => $player ? url('account') : url('signup'), 'action' => $player ? 'Your account' : 'Make an account',
                'done' => $player !== null,
                'status' => $player ? 'Signed in as ' . $player->login . '.' : null, 'progress' => null],
            ['key' => 'code', 'title' => 'Code drones',
                'text' => 'Pick a starter, found a startup and fix its code as it runs. At ' . number_format($mature)
                    . ' credits it repays the bank and its ' . Startups::SHARES . ' shares are yours.',
                'url' => url('getting-started'), 'action' => 'Your first startup',
                'done' => $matured > 0,
                'status' => $matured > 0 ? 'You own ' . $matured . ' matured ' . ($matured === 1 ? 'company' : 'companies') . '.' : null,
                'progress' => $player && $matured === 0 && $best > 0
                    ? ['now' => min($best, $mature), 'of' => $mature, 'label' => 'Your best startup: ' . number_format($best) . ' of ' . number_format($mature) . ' credits']
                    : null],
            ['key' => 'invest', 'title' => 'Invest',
                'text' => "Read a company's code, buy its shares and earn its dividends.",
                'url' => url('market/shares'), 'action' => 'Stock exchange',
                'done' => $invested > 0,
                'status' => $invested > 0 ? 'You hold shares in ' . $invested . ' other ' . ($invested === 1 ? 'company' : 'companies') . '.' : null,
                'progress' => null],
            ['key' => 'lend', 'title' => 'Lend',
                'text' => 'With ' . number_format($min) . ' credits, back companies\' loan requests and earn their interest.',
                'url' => url('market/loans'), 'action' => 'Loan requests',
                'done' => $loans > 0,
                'status' => $loans > 0 ? 'You have made ' . $loans . ' ' . ($loans === 1 ? 'loan' : 'loans') . '.' : null,
                'progress' => $player && $loans === 0
                    ? ['now' => min($credits, $min), 'of' => $min, 'label' => 'Your credits: ' . number_format($credits) . ' of ' . number_format($min)]
                    : null],
        ];
        $next = null;
        foreach ($steps as $i => &$s) {
            $s['n'] = $i + 1;
            if ($next === null && !$s['done']) {
                $next = $s['key'];
            }
        }
        unset($s);
        return ['steps' => $steps, 'next' => $next, 'player' => $player !== null];
    }

    /**
     * The rules in layers (#932): a newcomer sees the basics (their code,
     * startups, maturing, fuel and travel); owning a matured company adds
     * owning, going public and where an account's credits come from;
     * reaching the lending mark, or investing, adds loans. Nothing is
     * switched off: this is only what the strip under the menu shows.
     * [{layer, title, rules: [text], open}], each open or not yet
     */
    public static function rules(array $path): array
    {
        $done = array_column($path['steps'], 'done', 'key');
        $lend = collect($path['steps'])->firstWhere('key', 'lend');
        $credits = $lend['progress']['now'] ?? null;
        $mature = number_format(Startups::grant() + Startups::matureMargin());
        $min = number_format(Loans::minCredits());
        return [
            ['layer' => 1, 'title' => 'The basics', 'open' => true, 'rules' => [
                'Your Lua code runs your drones on their own, in a shared, live world.',
                'Found a startup for free: the bank lends it a drone and credits. Close it at any time and its debt is cancelled.',
                'A startup trades only with the House, the game\'s traders at each exchange, and you can change its code for free.',
                'At ' . $mature . ' credits it can mature: it repays the bank, trades with everyone, and its ' . Startups::SHARES . ' shares are yours.',
                'Fuel and travel: every flight and every job burns fuel, and a drone that runs dry is stuck where it is. Keep a reserve (fuel_floor) and buy more before you run low.',
            ]],
            ['layer' => 2, 'title' => 'Owning companies', 'open' => (bool) ($done['code'] ?? false), 'unlock' => 'when you own a matured company', 'rules' => [
                'You may own up to ' . (int) config('forge.startup_max') . ' companies outright, startups included, and change their code whenever you like.',
                'The first share you sell makes a company public and locks its code, which anyone can then read before they invest.',
                'Your account\'s credits come from your shares: what your companies pay out, and the shares you sell.',
            ]],
            ['layer' => 3, 'title' => 'Investing and lending', 'open' => (bool) (($done['invest'] ?? false) || ($done['lend'] ?? false)
                || ($credits !== null && $credits >= Loans::minCredits())), 'unlock' => 'when you invest, or have ' . $min . ' credits', 'rules' => [
                'Buy shares in other players\' companies at any price a seller will take, and earn what they pay out.',
                'Only public companies borrow: their code asks for loans, and players with ' . $min . ' credits choose which to back. Lenders are paid before shareholders.',
            ]],
        ];
    }
}
