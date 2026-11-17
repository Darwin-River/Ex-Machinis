<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Since you last looked" (#771): what changed for a player since they last
 * said they'd seen it, on the home page, the account page and their own
 * companies' pages; and a personal feed of their companies' latest trades.
 * Built on the digest (PlayerBoards::digest, without marking it seen), with
 * each company's share move and the action it fails most, with a hint at
 * the fix. Kept a real minute for each account.
 */
class SinceLooked
{
    public const TTL = 60;

    /** What a failure usually means, and what to try (by outcome code) */
    public const HINTS = [
        -1 => 'it acted on a drone that had moved on: check where the other drone is first',
        -2 => 'a tool, an input or fuel is missing: check the recipe\'s needs are aboard and not locked, and refuel earlier',
        -3 => 'the hold is full: sell or jettison before taking more',
        -4 => 'not enough credits: keep more back before buying, or sell first',
        -14 => 'no House station here trades it that way: use house_demand or house_supply to find one that does',
        -16 => 'a seedling trades only with the House: buy and sell from House orders',
        -19 => 'an order can\'t be cut or withdrawn in its first 12 game hours: post it right the first time',
    ];

    /** The card for an account: the digest's figures and these extras, or null with nothing to say */
    public static function for(User $user): ?array
    {
        if (!config('forge.new_rules') || $user->login === null) {
            return null;
        }
        return Cache::remember('since-looked:' . $user->user_id, self::TTL, fn () => self::build($user));
    }

    /** The player has seen it: the next card starts from now */
    public static function seen(User $user): void
    {
        PlayerBoards::markSeen($user, PlayerBoards::digest($user, false));
        Cache::forget('since-looked:' . $user->user_id);
    }

    private static function build(User $user): ?array
    {
        $d = PlayerBoards::digest($user, false);
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        $sinceDay = intdiv((int) ($d['until_t'] ?? World::gameNow()) - $epoch, 86400);
        $companies = [];
        $worst = null;
        foreach ($d['companies'] as $c) {
            $id = $c['company_id'];
            $row = $c;
            // Its share's move: cash-out a share now against the first day of the span
            if (!$c['seedling'] && !$c['ended'] && PlayerShares::tradable($id)) {
                $first = DB::table('company_days')->where('company_id', $id)->where('day', '>=', self::sinceDay($user, $epoch))
                    ->whereNotNull('cash_out')->orderBy('day')->first();
                $now = PlayerShares::prices($id)['buys_at'];
                // Over the shares it had that day (#780)
                $then = $first ? intdiv((int) $first->cash_out, max(1, (int) ($first->shares ?? PlayerShares::COUNT))) : null;
                $row['share_now'] = $now;
                $row['share_change'] = $then ? round(100 * ($now - $then) / $then, 1) : null;
            }
            // The action it fails most now, with a hint
            if (!$c['ended']) {
                $f = CompanyHealth::failing($id)[0] ?? null;
                if ($f && (!$worst || $f['times'] > $worst['times'])) {
                    $worst = $f + ['company_id' => $id, 'name' => $c['name'],
                        'hint' => self::hint($f['why'])];
                }
            }
            $companies[] = $row;
        }
        $card = ['since' => $d['since'], 'capped' => $d['capped'], 'companies' => $companies,
            'dividends' => $d['dividends'], 'bank_paid' => $d['bank_paid'], 'share_fills' => $d['share_fills'],
            'news' => array_slice($d['news'], 0, 3), 'failing' => $worst];
        $quiet = !$companies && !$d['dividends'] && !$d['bank_paid'] && !$d['share_fills'];
        return $quiet ? null : $card;
    }

    /** The game day the span starts on */
    private static function sinceDay(User $user, int $epoch): int
    {
        $since = (int) (DB::table('users')->where('user_id', $user->user_id)->value('last_seen_t') ?? World::gameNow() - 86400);
        $since = max($since, World::gameNow() - PlayerBoards::DIGEST_MAX_DAYS * 86400);
        return intdiv($since - $epoch, 86400);
    }

    /** A hint for a failure's reason, by matching its words to an outcome */
    private static function hint(string $why): ?string
    {
        foreach (self::HINTS as $code => $hint) {
            if ((DronePage::OUTCOMES[$code] ?? null) === $why) {
                return $hint;
            }
        }
        return null;
    }

    /**
     * The player's own latest happenings (#771): their companies' latest
     * trades and their own share trades, newest first, each {when, text,
     * game_time}
     */
    public static function feed(User $user, int $limit = 6): array
    {
        if (!config('forge.new_rules') || $user->login === null) {
            return [];
        }
        $ids = DB::table('companies')->where('owner_id', $user->user_id)->whereNull('eliminated_at')->pluck('company_id')->all();
        $rows = [];
        if ($ids) {
            foreach (DB::table('trades as t')->join('agents as a', function ($j) {
                    $j->on('a.agent_id', '=', 't.seller')->orOn('a.agent_id', '=', 't.buyer');
                })->join('companies as c', 'c.company_id', '=', 'a.company_id')->leftJoin('resources as r', 'r.id', '=', 't.resource')
                ->leftJoin('objects as o', 'o.object_id', '=', 't.location')
                ->whereIn('a.company_id', $ids)->orderByDesc('t.id')->limit($limit)
                ->get(['t.id', 't.game_t', 't.units', 't.credits', 't.seller', 'a.agent_id', 'a.name as drone', 'c.name as company',
                    'r.name as resource', 'o.object_name as place']) as $t) {
                $sold = (int) $t->seller === (int) $t->agent_id;
                $rows[] = ['game_time' => (int) $t->game_t, 'when' => DronePage::when((int) $t->game_t),
                    'text' => $t->company . "'s " . $t->drone . ' ' . ($sold ? 'sold' : 'bought') . ' ' . number_format((int) $t->units)
                        . ' ' . ($t->resource ?? 'goods') . ($t->place ? ' at ' . $t->place : '') . ' for ' . number_format((int) $t->credits)];
            }
        }
        foreach (PlayerShares::fills((int) $user->user_id, 0, $limit) as $f) {
            $rows[] = ['game_time' => (int) $f['game_time'], 'when' => $f['when'],
                'text' => 'You ' . $f['side'] . ' ' . number_format($f['units']) . ' ' . $f['name'] . ' shares at ' . number_format($f['price'])
                    . ' ' . ($f['side'] === 'sold' ? 'to' : 'from') . ' ' . $f['with']];
        }
        usort($rows, fn ($a, $b) => $b['game_time'] <=> $a['game_time']);
        return array_slice($rows, 0, $limit);
    }
}
