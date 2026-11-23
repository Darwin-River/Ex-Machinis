<?php

namespace App\Services;

use App\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Code pushes (#553): a company released with a password can send its
 * working drones a new script. Round 58's players had to release a new
 * company, starting from day 0, for every fix ("a bad design ran for 50 days
 * and stayed bad").
 *
 * - The password is kept only as a hash and checked against it.
 * - The fee is a share of the company's credits that grows with each push
 *   (forge.push_fee_permille times the push's number, at most
 *   forge.push_fee_cap_permille), so a young or failing company is cheap to
 *   fix and tinkering gets dear. A company with no credits pays nothing.
 * - Each working drone gets <reset>, the script's pieces and <launched> as
 *   commands. The engine stamps them to arrive at the speed of light from
 *   Earth; <reset> drops the running program and its variables, and the
 *   drone starts the new launched() with what it holds, where it is.
 */
class CodePush
{
    /** A password's hash, for companies.push_password (null: no password) */
    public static function hash(?string $password): ?string
    {
        return $password === null || $password === '' ? null : Hash::make($password);
    }

    /** Sets a company's push password hash (a release, or a queued one launching) */
    public static function setHash(int $company, ?string $hash): void
    {
        if ($hash !== null) {
            DB::table('companies')->where('company_id', $company)->update(['push_password' => $hash]);
        }
    }

    /** Thousandths of its credits the company's next push costs */
    public static function permille(int $pushes): int
    {
        return min((int) config('forge.push_fee_cap_permille'), (int) config('forge.push_fee_permille') * ($pushes + 1));
    }

    /** What the company's next push costs: [permille, fee] */
    public static function nextFee(object $company): array
    {
        $permille = self::permille((int) $company->pushes);
        return [$permille, intdiv(max(0, (int) $company->credits) * $permille, 1000)];
    }

    /**
     * Pushes a script to the company's working drones. Returns
     * ['ok' => true, number, fee, permille, drones, next_permille] or
     * ['ok' => false, 'status' => HTTP status, 'error' => why]
     */
    public static function push(int $companyId, string $password, string $script): array
    {
        $company = DB::table('companies')->where('company_id', $companyId)->first();
        if (!$company || (int) $company->house === 1) {
            return ['ok' => false, 'status' => 404, 'error' => 'There is no such company.'];
        }
        // Under the new rules (#635), a startup is reprogrammed by its player
        // (Startups::reprogram), and a matured company's code is locked
        if ((int) ($company->startup ?? 0) === 1) {
            return ['ok' => false, 'status' => 403, 'error' => 'A startup is reprogrammed by its player, from their account.'];
        }
        if (($company->owner_id ?? null) !== null) {
            return ['ok' => false, 'status' => 403, 'error' => 'A mature company\'s code is locked: nobody can push to it.'];
        }
        if ($company->push_password === null) {
            return ['ok' => false, 'status' => 403,
                'error' => 'This company was released without a password, so no code can be pushed to it.'];
        }
        if (!Hash::check($password, $company->push_password)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Wrong password.'];
        }
        if ($company->eliminated_at !== null) {
            return ['ok' => false, 'status' => 409, 'error' => 'This company has ended: it has no drones to push to.'];
        }
        if ($problem = Release::scriptProblem($script)) {
            return ['ok' => false, 'status' => 422, 'error' => $problem];
        }
        $drones = DB::table('agents')->where('company_id', $companyId)->whereNull('wrecked_at')
            ->whereNull('station_id')->where('language', 'lua')->orderBy('agent_id')->pluck('agent_id')->all();
        if (!$drones) {
            return ['ok' => false, 'status' => 409, 'error' => 'This company has no working drone to push to.'];
        }

        return DB::transaction(function () use ($company, $companyId, $script, $drones) {
            // The fee on the credits it has now; a push racing another fails
            [$permille, $fee] = self::nextFee($company);
            $paid = DB::table('companies')->where('company_id', $companyId)->where('pushes', $company->pushes)
                ->update(['credits' => DB::raw('credits - ' . $fee), 'pushes' => DB::raw('pushes + 1')]);
            if (!$paid) {
                return ['ok' => false, 'status' => 409, 'error' => 'Another push is under way: try again.'];
            }
            CreditFlows::record('push_fee', -$fee, $companyId);   // #937
            $number = (int) $company->pushes + 1;
            DB::table('code_pushes')->insert(['company_id' => $companyId, 'number' => $number,
                'game_t' => World::gameNow(), 'credits_before' => (int) $company->credits, 'permille' => $permille,
                'fee' => $fee, 'drones' => count($drones), 'script' => $script]);
            self::send($drones, $script);
            return ['ok' => true, 'number' => $number, 'fee' => $fee, 'permille' => $permille, 'drones' => $drones,
                'next_permille' => self::permille($number)];
        });
    }

    /** Sends a script to drones: each starts over on it when it arrives */
    public static function send(array $drones, string $script): void
    {
        $pieces = Release::chunks($script);
        // The old code's status line goes with it (#674: "hauling 12 rods to
        // Earth" stayed for 175 game days after two pushes)
        DB::table('agents')->whereIn('agent_id', $drones)->update(['script_status' => null, 'script_status_t' => null]);
        foreach ($drones as $drone) {
            Command::create(['code' => '<reset>', 'agent_id' => $drone, 'subject' => 'Push', 'email_content' => '']);
            foreach ($pieces as $piece) {
                Command::create(['code' => $piece, 'agent_id' => $drone, 'subject' => 'Push', 'email_content' => '']);
            }
            Command::create(['code' => '<launched>', 'agent_id' => $drone, 'subject' => 'Push', 'email_content' => '']);
        }
    }

    /**
     * What pushing to the company would cost now (#558), without charging or
     * sending anything: null when there is no such company
     */
    public static function preview(int $companyId): ?array
    {
        $company = DB::table('companies')->where('company_id', $companyId)->where('house', 0)->first();
        if (!$company) {
            return null;
        }
        $drones = DB::table('agents')->where('company_id', $companyId)->whereNull('wrecked_at')
            ->whereNull('station_id')->where('language', 'lua')->count();
        // The new rules (#653, #923): the owner of a startup, or of a matured
        // company whose shares they hold all of, reprograms it for free; a
        // public company's code is locked
        if ((int) $company->startup === 1) {
            $fee = Startups::pushFee($company);
            return ['startup' => true, 'enabled' => $company->eliminated_at === null && $drones > 0,
                'number' => (int) $company->pushes + 1, 'fee' => $fee, 'debt' => (int) $company->debt,
                'debt_after' => (int) $company->debt + $fee, 'drones' => $drones,
                'note' => 'Its owner reprograms a startup from its page or POST /api/v1/startups/'
                    . $companyId . '/push, for free.'];
        }
        if ($company->owner_id !== null) {
            $outright = Ownership::owner($company) !== null;
            return ['startup' => false, 'enabled' => $outright && $company->eliminated_at === null && $drones > 0, 'drones' => $drones,
                'fee' => 0, 'outright' => $outright,
                'note' => $outright ? 'One player owns all its shares: they reprogram it from its page or POST /api/v1/startups/'
                    . $companyId . '/push, for free, until they sell a share.'
                    : 'Its shares are held by more than one holder: its code is locked and can\'t be pushed to.'];
        }
        [$permille, $fee] = self::nextFee($company);
        // Each drone's standing orders for goods (#571): they outlive the push
        $ids = DB::table('agents')->where('company_id', $companyId)->whereNull('wrecked_at')->whereNull('station_id')
            ->pluck('agent_id')->all();
        $orders = DB::table('latest_orders AS o')->leftJoin('resources AS r', 'r.id', '=', 'o.resource')
            ->whereIn('o.drone', $ids)
            ->where(fn ($q) => $q->where(fn ($s) => $s->where('o.event_type', 4)->where('o.quantity', '<', 1000000))
                ->orWhere(fn ($b) => $b->where('o.event_type', 5)->where('o.quantity', '>', 0)))
            ->orderBy('o.drone')->orderBy('o.resource')
            ->get(['o.drone', 'o.resource', 'r.name', 'o.event_type', 'o.price', 'o.quantity'])
            ->map(fn ($o) => ['drone' => (int) $o->drone, 'resource' => (int) $o->resource, 'name' => $o->name,
                'side' => (int) $o->event_type === 4 ? 'sell' : 'buy', 'price' => (int) $o->price,
                ((int) $o->event_type === 4 ? 'keep' : 'want') => (int) $o->quantity])->all();
        return ['orders' => $orders,
            'orders_note' => $orders ? 'These standing orders stay in force after the push. Call cancel_orders() first in '
                . 'the new launched() if it should not keep them.' : null,
            'enabled' => $company->push_password !== null && $company->eliminated_at === null && $drones > 0,
            'password_set' => $company->push_password !== null, 'number' => (int) $company->pushes + 1,
            'credits' => (int) $company->credits, 'fee_percent' => $permille / 10, 'fee' => $fee, 'drones' => $drones,
            'note' => 'The fee is worked out on the company\'s credits when the push is sent, so it changes as they do.'];
    }

    /** A company's pushes, newest first: number, when, fee, permille, drones */
    public static function log(int $companyId): array
    {
        return DB::table('code_pushes')->where('company_id', $companyId)->orderByDesc('id')
            ->get(['number', 'game_t', 'fee', 'permille', 'drones'])
            ->map(fn ($p, $i) => ['number' => (int) $p->number, 'when' => DronePage::when((int) $p->game_t),
                'game_time' => (int) $p->game_t, 'fee' => (int) $p->fee, 'percent' => $p->permille / 10,
                'drones' => (int) $p->drones,
                // Its effect (#772), for the latest few
                'effect' => $i < self::EFFECT_SHOWN ? self::effect($companyId, (int) $p->game_t) : null])->all();
    }

    /** Game days compared on each side of a push (#772) */
    public const EFFECT_DAYS = 3;

    /** Pushes whose effect is worked out, newest first */
    public const EFFECT_SHOWN = 3;

    /** The least time after a push before it is compared, in game seconds */
    public const EFFECT_MIN = 6 * 3600;

    /**
     * "This change did X" (#772): a push against the code before it. The
     * same span on each side (up to EFFECT_DAYS game days, and never back
     * past the push before it or the company's founding): its trading net
     * (sales less tax, less purchases), sales and failed actions, each a
     * game day, with equipment left out of its purchases. Null when it is
     * too early to tell or there is nothing before
     */
    public static function effect(int $companyId, int $pushT, ?int $now = null): ?array
    {
        $now ??= (int) World::gameNow();
        $prev = DB::table('code_pushes')->where('company_id', $companyId)->where('game_t', '<', $pushT)->max('game_t');
        $from = max((int) ($prev ?? 0), (int) (DB::table('companies')->where('company_id', $companyId)->value('founded_t') ?? 0));
        $span = min(self::EFFECT_DAYS * 86400, $now - $pushT, $pushT - $from);
        if ($span < self::EFFECT_MIN) {
            return ['ready' => false, 'span_days' => 0,
                'note' => $now - $pushT < self::EFFECT_MIN ? 'too early to tell: it compares at least 6 game hours after the push'
                    : 'too little before it to compare with'];
        }
        $before = self::window($companyId, $pushT - $span, $pushT, $span);
        $after = self::window($companyId, $pushT, $pushT + $span, $span);
        $change = fn ($a, $b) => $a == 0 ? null : (int) round(100 * ($b - $a) / abs($a));
        return ['ready' => true, 'span_days' => round($span / 86400, 2), 'before' => $before, 'after' => $after,
            'net_change_percent' => $change($before['net'], $after['net']),
            'sales_change_percent' => $change($before['sales'], $after['sales']),
            'verdict' => self::verdict($before, $after)];
    }

    /** A company's trading and failures a game day over [from, to) */
    private static function window(int $companyId, int $from, int $to, int $span): array
    {
        $days = $span / 86400;
        $sold = DB::table('trades as t')->join('agents as a', 'a.agent_id', '=', 't.seller')->where('a.company_id', $companyId)
            ->where('t.game_t', '>=', $from)->where('t.game_t', '<', $to)
            ->selectRaw('COALESCE(SUM(t.credits), 0) AS c, COALESCE(SUM(t.tax), 0) AS tax')->first();
        // Equipment it bought (kits, modules, hulls) is invested, not spent, as
        // on its drones table (#879: a kit bought after a push read as a loss)
        $bought = (int) DB::table('trades as t')->join('agents as a', 'a.agent_id', '=', 't.buyer')->where('a.company_id', $companyId)
            ->where('t.game_t', '>=', $from)->where('t.game_t', '<', $to)
            ->where('t.resource', '<', CompanyEarnings::EQUIPMENT_FROM)->sum('t.credits');
        $failed = 'x.outcome < 0 AND x.outcome NOT IN (' . implode(', ', CompanyHealth::MARKET_REFUSALS) . ')';
        $acts = DB::selectOne("SELECT COUNT(DISTINCT x.id) AS tries, COUNT(DISTINCT CASE WHEN $failed THEN x.id END) AS failed
            FROM agents AS a JOIN events AS e FORCE INDEX (events_drone_game_t) ON e.drone = a.agent_id
              AND e.game_t >= ? AND e.game_t < ?
            JOIN actions AS x ON x.id = e.action AND x.drone = a.agent_id AND x.protocol <> 2
            WHERE a.company_id = ?", [$from, $to, $companyId]);
        $sales = (int) $sold->c - (int) $sold->tax;
        return ['net' => (int) round(($sales - $bought) / $days), 'sales' => (int) round($sales / $days),
            'bought' => (int) round($bought / $days), 'actions' => (int) round((int) $acts->tries / $days, 1),
            'failed' => round((int) $acts->failed / $days, 1)];
    }

    /** One line a student can judge it by */
    private static function verdict(array $b, array $a): string
    {
        $parts = [];
        if ($a['net'] !== $b['net']) {
            $parts[] = 'it ' . ($a['net'] > $b['net'] ? 'earns more' : 'earns less') . ' from trading (' . number_format($b['net'])
                . ' → ' . number_format($a['net']) . ' a game day)';
        }
        if (abs($a['failed'] - $b['failed']) >= 1) {
            $parts[] = 'it fails ' . ($a['failed'] < $b['failed'] ? 'less' : 'more') . ' (' . $b['failed'] . ' → ' . $a['failed'] . ' failed actions a game day)';
        }
        if ($a['actions'] < $b['actions'] / 2) {
            $parts[] = 'it does less than half as much';
        }
        return $parts ? ucfirst(implode('; ', $parts)) : 'No clear change yet';
    }

    /** What the company page and API say about pushes */
    public static function summary(object $company): array
    {
        // The new rules (#666, #923): a startup's owner, or a matured
        // company's sole shareholder, reprograms it for free; a public
        // company's code is locked
        if ((int) ($company->startup ?? 0) === 1) {
            $fee = Startups::pushFee($company);
            return ['enabled' => $company->eliminated_at === null, 'startup' => true, 'count' => (int) $company->pushes,
                'next_fee' => $fee, 'debt' => (int) $company->debt, 'debt_after' => (int) $company->debt + $fee,
                'log' => self::log((int) $company->company_id)];
        }
        if (($company->owner_id ?? null) !== null) {
            $locked = $company->eliminated_at !== null || Ownership::owner($company) === null;
            return ['enabled' => !$locked, 'locked' => $locked, 'count' => (int) $company->pushes,
                'log' => self::log((int) $company->company_id)];
        }
        [$permille, $fee] = self::nextFee($company);
        return ['enabled' => $company->push_password !== null, 'count' => (int) $company->pushes,
            'next_fee_percent' => $permille / 10, 'next_fee' => $fee, 'log' => self::log((int) $company->company_id)];
    }
}
