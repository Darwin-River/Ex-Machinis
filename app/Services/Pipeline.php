<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The player's path through the game: the Player Ladder (#1039), grown from
 * the four steps of #987 to eight, each a small step with one thing to do:
 * watch a drone, try one, keep it (an account), found a startup, fix it as
 * it runs, own a matured company (claim its shares), invest, lend. The same
 * steps on the home page, the step pages' progress line, the account page and
 * the strip under the menu, each with where this player is on it. Nothing is
 * locked but buying shares, which opens once a player owns a matured company
 * (#1040).
 */
class Pipeline
{
    /** The session keys a visitor's first two steps are kept under */
    public const WATCHED = 'ladder.watched';
    public const TRIED = 'ladder.tried';

    /**
     * The steps: {key, n, title, text, url, action, done, status, progress}.
     * For a player, done says whether they have done it, and progress (where
     * there is one) is {now, of, label}. A visitor's watch and try come from
     * their session; a player is past them. 'next' is the key of the first
     * step not yet done (or null when all are).
     */
    public static function for(?User $user): array
    {
        $player = $user && $user->login !== null ? $user : null;
        $id = $player ? (int) $player->user_id : null;
        $min = Loans::minCredits();
        $grant = Startups::grant();
        $mature = $grant + Startups::matureMargin();
        $session = app()->bound('session') ? session() : null;

        $credits = $id ? (int) DB::table('users')->where('user_id', $id)->value('credits') : 0;
        $founded = $id ? DB::table('companies')->where('owner_id', $id)->count() : 0;
        $matured = $id ? DB::table('companies')->where('owner_id', $id)->whereNotNull('matured_t')->count() : 0;
        $pushed = $id && Schema::hasTable('code_pushes') ? DB::table('code_pushes as p')
            ->join('companies as c', 'c.company_id', '=', 'p.company_id')->where('c.owner_id', $id)->exists() : false;
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
        $mayInvest = $id ? Ownership::mayInvest($id) : false;
        $n = fn ($v) => number_format($v);

        $steps = [
            ['key' => 'watch', 'title' => 'Watch the game',
                'text' => 'Drones at work across the solar system right now, each run by a player\'s code, and the economy they make.',
                'url' => url('/') . '#home-live', 'action' => 'Watch it',
                'done' => $player !== null || (bool) ($session?->get(self::WATCHED)), 'status' => null, 'progress' => null],
            ['key' => 'try', 'title' => 'Fly a drone',
                'text' => 'Join the Economy: a first drone in a few lines. Choose its route, test drive it and see what it would do. No account needed.',
                'url' => url('try'), 'action' => 'Join the Economy',
                'done' => $player !== null || (bool) ($session?->get(self::TRIED)), 'status' => null, 'progress' => null],
            ['key' => 'account', 'title' => 'Keep it',
                'text' => 'A free account keeps your drone, the companies you start and what they earn you.',
                'url' => $player ? url('account') : url('signup'), 'action' => $player ? 'Your account' : 'Keep my drone',
                'done' => $player !== null,
                'status' => $player ? 'Signed in as ' . $player->login . '.' : null, 'progress' => null],
            ['key' => 'found', 'title' => 'Found a startup',
                'text' => 'Your code runs a company of its own. The bank lends it ' . $n($grant) . ' credits, and if it crashes, its debt is cancelled.',
                'url' => url('release'), 'action' => 'Found a startup',
                'done' => $founded > 0,
                'status' => $founded > 0 ? 'You have founded ' . $founded . ' ' . ($founded === 1 ? 'startup' : 'startups') . '.' : null,
                'progress' => null],
            ['key' => 'fix', 'title' => 'Fix it as it runs',
                'text' => 'Watch what it does, change a number and push the fix. Pushing a startup\'s code is free.',
                'url' => url('getting-started'), 'action' => 'Your first fix',
                'done' => $pushed || $matured > 0, 'status' => $pushed ? 'You have pushed a fix.' : null, 'progress' => null],
            ['key' => 'own', 'title' => 'Claim your shares',
                'text' => 'At ' . $n($mature) . ' credits it repays the bank: claim its ' . Startups::SHARES . ' shares and the company is yours.',
                'url' => url('account') . '#startups', 'action' => 'Your startups',
                'done' => $matured > 0,
                'status' => $matured > 0 ? 'You own ' . $matured . ' matured ' . ($matured === 1 ? 'company' : 'companies') . '.' : null,
                'progress' => $player && $matured === 0 && $best > 0
                    ? ['now' => min($best, $mature), 'of' => $mature, 'label' => 'Your best startup: ' . $n($best) . ' of ' . $n($mature) . ' credits']
                    : null],
            ['key' => 'invest', 'title' => 'Invest',
                'text' => $mayInvest || !$player ? "Read a company's code, buy a share and earn what it pays out."
                    : 'Opens once you own a matured company: then buy a share of another and earn what it pays out.',
                'url' => url('market/shares/first'), 'action' => 'Your first share',
                'done' => $invested > 0,
                'status' => $invested > 0 ? 'You hold shares in ' . $invested . ' other ' . ($invested === 1 ? 'company' : 'companies') . '.' : null,
                'progress' => null],
            ['key' => 'lend', 'title' => 'Lend',
                'text' => 'With ' . $n($min) . ' credits, back a company\'s loan request and earn its interest.',
                'url' => url('market/loans/first'), 'action' => 'Your first loan',
                'done' => $loans > 0,
                'status' => $loans > 0 ? 'You have made ' . $loans . ' ' . ($loans === 1 ? 'loan' : 'loans') . '.' : null,
                'progress' => $player && $loans === 0
                    ? ['now' => min($credits, $min), 'of' => $min, 'label' => 'Your credits: ' . $n($credits) . ' of ' . $n($min)]
                    : null],
        ];
        $next = null;
        // Watching is step 0 (#1049): the steps a player takes are numbered from 1 after it
        foreach ($steps as $i => &$s) {
            $s['n'] = $i;
            if ($next === null && !$s['done']) {
                $next = $s['key'];
            }
        }
        unset($s);
        return ['steps' => $steps, 'next' => $next, 'player' => $player !== null];
    }

    /**
     * for() and rules() for a player, kept a minute (#932): the strip under
     * the menu and the menu's Next button (#1041) share it.
     * CompanyNow::forget() drops it when a step moves on.
     */
    public static function cached(User $user): array
    {
        return \Illuminate\Support\Facades\Cache::remember('next-strip:' . $user->user_id, 60, function () use ($user) {
            $path = self::for($user);
            return ['path' => $path, 'rules' => self::rules($path)];
        });
    }

    /**
     * How many accounts have reached each step (#1045): counts only, no
     * names. People's accounts only: the game's own and Claude players
     * (#1025) are left out, so the counts measure the path people take.
     * $days: only accounts made in the last $days real days (null: all).
     * {accounts, found, fix, own, invest, lend}
     */
    public static function counts(?int $days = null): array
    {
        $people = fn () => DB::table('users')->whereNotNull('login')
            ->when(Schema::hasColumn('users', 'bot_operator_id'), fn ($q) => $q->whereNull('bot_operator_id'))
            ->when($days !== null, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))->select('user_id');
        $owners = fn () => DB::table('companies')->whereIn('owner_id', $people());
        $fixed = Schema::hasTable('code_pushes') ? DB::table('code_pushes as p')->join('companies as c', 'c.company_id', '=', 'p.company_id')
            ->whereIn('c.owner_id', $people())->distinct()->pluck('c.owner_id')->all() : [];
        $matured = $owners()->whereNotNull('matured_t')->distinct()->pluck('owner_id')->all();
        return [
            'accounts' => $people()->count(),
            'found' => $owners()->distinct()->count('owner_id'),
            'fix' => count(array_unique(array_merge($fixed, $matured))),
            'own' => count($matured),
            // Bought a share of a company they didn't found
            'invest' => Schema::hasTable('player_share_trades') ? DB::table('player_share_trades as t')
                ->join('companies as c', 'c.company_id', '=', 't.company_id')->whereIn('t.buyer_id', $people())
                ->where(fn ($q) => $q->whereNull('c.owner_id')->orWhereColumn('c.owner_id', '!=', 't.buyer_id'))
                ->distinct()->count('t.buyer_id') : 0,
            'lend' => Schema::hasTable('loans') ? DB::table('loans')->whereIn('user_id', $people())->distinct()->count('user_id') : 0,
        ];
    }

    /** The step a page is, by key, or null */
    public static function step(array $path, string $key): ?array
    {
        return collect($path['steps'])->firstWhere('key', $key);
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
            ['layer' => 2, 'title' => 'Owning companies', 'open' => (bool) ($done['own'] ?? false), 'unlock' => 'when you own a matured company', 'rules' => [
                'You may own up to ' . (int) config('forge.startup_max') . ' companies outright, startups included, and change their code whenever you like.',
                'The first share you sell makes a company public and locks its code, which anyone can then read before they invest.',
                'Your account\'s credits come from your shares: what your companies pay out, and the shares you sell.',
                'Owning a matured company opens investing: from then on you can buy shares in other players\' companies.',
            ]],
            ['layer' => 3, 'title' => 'Investing and lending', 'open' => (bool) (($done['invest'] ?? false) || ($done['lend'] ?? false)
                || ($credits !== null && $credits >= Loans::minCredits())), 'unlock' => 'when you invest, or have ' . $min . ' credits', 'rules' => [
                'Once you own a matured company, buy shares in other players\' companies at any price a seller will take, and earn what they pay out.',
                'Only public companies borrow: their code asks for loans, and players with ' . $min . ' credits choose which to back. Lenders are paid before shareholders.',
            ]],
        ];
    }
}
