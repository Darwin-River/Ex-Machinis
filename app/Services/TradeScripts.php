<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The account trading script (#768): one Lua script a player may run on the
 * share market while away. Every few real minutes the scheduler's keeper
 * runs each switched-on script once (runAll) in the lua-trade sandbox
 * (game-engine/src/lua_trade.c): its trade(market) gets the share market
 * and the account now, and asks for buys, sells, pegged orders, cancels and
 * bank trades, which go through the player's own rules here
 * (PlayerShares), at most MAX_ACTIONS a run. What it keeps in `memory`
 * comes back next run; its last runs' results are logged for the account
 * page and the API.
 */
class TradeScripts
{
    /** Runs of results kept in the log */
    public const LOG_RUNS = 20;

    /** The longest script, in bytes */
    public const MAX_CODE = 65536;

    /** Real seconds a run may take */
    public const TIMEOUT = 5;

    public static function available(): bool
    {
        return Schema::hasTable('player_trade_scripts');
    }

    /** An account's script: {code, enabled, runs, last_run, log}, or null */
    public static function of(User $user): ?array
    {
        if (!self::available()) {
            return null;
        }
        $r = DB::table('player_trade_scripts')->where('user_id', $user->user_id)->first();
        if (!$r) {
            return null;
        }
        return ['code' => $r->code, 'enabled' => (bool) $r->enabled, 'runs' => (int) $r->runs,
            'last_run' => $r->last_run_t ? DronePage::when((int) $r->last_run_t) : null,
            'log' => self::log($r->log)];
    }

    /** Its log: the latest runs, newest first, each {when, lines: [text]} */
    private static function log(?string $log): array
    {
        $runs = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", (string) $log))));
        return array_reverse($runs);
    }

    /**
     * Saves an account's script (and whether it runs): ['ok' => true] or
     * ['ok' => false, 'status', 'error'] when it doesn't compile or is too long
     */
    public static function save(User $user, string $code, bool $enabled): array
    {
        if (!self::available()) {
            return ['ok' => false, 'status' => 503, 'error' => 'Trading scripts aren\'t available yet.'];
        }
        if (strlen($code) > self::MAX_CODE) {
            return ['ok' => false, 'status' => 422, 'error' => 'A trading script is at most ' . number_format(self::MAX_CODE) . ' bytes.'];
        }
        if (trim($code) === '') {
            DB::table('player_trade_scripts')->where('user_id', $user->user_id)->delete();
            return ['ok' => true, 'removed' => true];
        }
        if ($problem = Release::luaProblem($code)) {
            return ['ok' => false, 'status' => 422, 'error' => $problem];
        }
        DB::table('player_trade_scripts')->updateOrInsert(['user_id' => $user->user_id],
            ['code' => $code, 'enabled' => $enabled ? 1 : 0, 'updated_at' => now()]);
        return ['ok' => true];
    }

    /**
     * Runs every switched-on script that is due (not run in the last
     * forge.trade_script_every real seconds): how many ran
     */
    public static function runAll(): int
    {
        if (!self::available() || !config('forge.new_rules')) {
            return 0;
        }
        $due = time() - (int) config('forge.trade_script_every');
        $ran = 0;
        foreach (DB::table('player_trade_scripts')->where('enabled', 1)
                     ->where(fn ($q) => $q->whereNull('last_real_t')->orWhere('last_real_t', '<=', $due))
                     ->orderBy('last_real_t')->limit(200)->pluck('user_id') as $id) {
            $user = User::find($id);
            if ($user) {
                self::run($user);
                $ran++;
            }
        }
        return $ran;
    }

    /**
     * Runs an account's script once now: the lines it logged and what its
     * actions did, or null when it has none or the sandbox isn't installed
     */
    public static function run(User $user): ?array
    {
        $row = self::available() ? DB::table('player_trade_scripts')->where('user_id', $user->user_id)->first() : null;
        $runner = config('forge.lua_trade');
        if (!$row || !$runner || !is_executable($runner)) {
            return null;
        }
        $dir = sys_get_temp_dir();
        $files = ['script' => tempnam($dir, 'trade'), 'market' => tempnam($dir, 'market'), 'memory' => tempnam($dir, 'memory')];
        file_put_contents($files['script'], $row->code);
        file_put_contents($files['market'], 'return ' . self::lua(self::market($user)));
        file_put_contents($files['memory'], (string) $row->memory);
        [$lines, $failed] = self::sandbox($runner, $files);
        foreach ($files as $f) {
            @unlink($f);
        }

        $out = [];
        $memory = $row->memory;
        foreach ($lines as $line) {
            $f = explode("\t", $line);
            switch ($f[0]) {
                case 'log':
                    $out[] = 'says: ' . ($f[1] ?? '');
                    break;
                case 'memory':
                    $memory = $f[1] ?? $memory;
                    break;
                case 'error':
                    $out[] = 'stopped: ' . ($f[1] ?? 'it failed');
                    break;
                default:
                    $out[] = self::apply($user, $f);
            }
        }
        if ($failed && !$lines) {
            $out[] = 'stopped: ' . $failed;
        }
        $entry = json_encode(['when' => DronePage::when(World::gameNow()), 'lines' => $out ?: ['did nothing']]);
        $log = array_slice(array_filter(explode("\n", (string) $row->log)), -(self::LOG_RUNS - 1));
        $log[] = $entry;
        DB::table('player_trade_scripts')->where('user_id', $user->user_id)->update(['memory' => $memory,
            'last_run_t' => World::gameNow(), 'last_real_t' => time(), 'runs' => DB::raw('runs + 1'), 'log' => implode("\n", $log)]);
        return $out;
    }

    /** Runs lua-trade: [its output lines, why it failed or null] */
    private static function sandbox(string $runner, array $files): array
    {
        $process = proc_open([$runner, $files['script'], $files['market'], $files['memory']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [[], 'the sandbox didn\'t start'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $out = '';
        $start = microtime(true);
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) - $start > self::TIMEOUT || strlen($out) > 1048576) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return [[], 'it ran for more than ' . self::TIMEOUT . ' real seconds'];
            }
            usleep(10000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return [array_values(array_filter(explode("\n", $out), fn ($l) => $l !== '')), null];
    }

    /** Applies one action through the player's own rules: what happened, in words */
    private static function apply(User $user, array $f): string
    {
        $c = (int) ($f[1] ?? 0);
        $name = (string) (DB::table('companies')->where('company_id', $c)->value('name') ?? "company $c");
        $describe = function (array $r, string $what) {
            if (!($r['ok'] ?? false)) {
                return $what . ': refused, ' . lcfirst(rtrim((string) ($r['error'] ?? 'it failed'), '.'));
            }
            return $what . ': traded ' . (int) ($r['traded'] ?? 0) . (($r['standing'] ?? 0) > 0 ? ', ' . (int) $r['standing'] . ' stand' : '')
                . (isset($r['price']) ? ' (at ' . $r['price'] . ')' : '');
        };
        switch ($f[0]) {
            case 'buy':
            case 'sell':
                return $describe(PlayerShares::order($user, $c, $f[0], (int) ($f[2] ?? 0), (int) ($f[3] ?? 0), null, null, true),
                    "{$f[0]} {$f[2]} of $name at {$f[3]}");
            case 'buy_pegged':
            case 'sell_pegged':
                $side = $f[0] === 'buy_pegged' ? 'buy' : 'sell';
                return $describe(PlayerShares::order($user, $c, $side, (int) ($f[2] ?? 0), 0,
                    ['ref' => (string) ($f[3] ?? ''), 'offset_bp' => (int) round((float) ($f[4] ?? 0) * 100)], null, true),
                    "$side {$f[2]} of $name at {$f[3]} {$f[4]}%");
            case 'cancel':
                return PlayerShares::cancel($user, $c) ? "cancel order $c: done" : "cancel order $c: no such order of yours";
            case 'bank_sell':
                $r = PlayerShares::sellToBank($user, $c, (int) ($f[2] ?? 0));
                return ($r['ok'] ?? false) ? "sell {$f[2]} of $name to the bank: done" : "sell {$f[2]} of $name to the bank: refused, " . lcfirst(rtrim((string) $r['error'], '.'));
            case 'bank_buy':
                $r = PlayerShares::buyFromBank($user, $c, (int) ($f[2] ?? 0));
                return ($r['ok'] ?? false) ? "buy {$f[2]} of $name from the bank: done (at {$r['price']})" : "buy {$f[2]} of $name from the bank: refused, " . lcfirst(rtrim((string) $r['error'], '.'));
        }
        return 'unknown action ' . $f[0];
    }

    /**
     * What a trading script sees (#768): the share market and the account
     * now. companies: every matured company still trading, with its prices,
     * range, the bank's shares, dividends, yield, return, health and its
     * book; me: the account's credits, holdings, orders and what the bank
     * owes it; now: the game time and day
     */
    public static function market(User $user): array
    {
        $board = \App\Http\Controllers\WorldController::sharesPage()['playerMarket'] ?? [];
        $companies = [];
        foreach ($board as $m) {
            $id = (int) $m['company'];
            $p = PlayerShares::prices($id);
            $book = PlayerShares::book($id);
            $companies[] = ['company' => $id, 'name' => $m['name'], 'buys_at' => $p['buys_at'], 'sells_at' => $p['sells_at'],
                'low' => $p['low'], 'high' => $p['high'], 'bank_holds' => $p['bank_holds'],
                'dividends30' => $m['dividends30'] ?? 0, 'yield_percent' => $m['yield_percent'] ?? null,
                'growth_percent' => $m['growth_percent'] ?? null, 'return_percent' => $m['return_percent'] ?? null,
                'last_price' => $m['last_price'] ?? null, 'last_days_ago' => $m['last_days_ago'] ?? null,
                'stalled' => $m['stalled'] ?? false, 'at_risk' => $m['at_risk'] ?? false, 'failing' => $m['failing'] ?? false,
                'dissolves_in' => $m['dissolves_in'] ?? null, 'winds_up_in' => $m['winds_up_in'] ?? null,
                'bids' => array_map(fn ($o) => ['price' => $o['price'], 'units' => $o['units'], 'by' => $o['by'], 'script' => $o['script']], $book['buy']),
                'asks' => array_map(fn ($o) => ['price' => $o['price'], 'units' => $o['units'], 'by' => $o['by'], 'script' => $o['script']], $book['sell'])];
        }
        $mine = PlayerShares::of($user);
        $account = DB::table('users')->where('user_id', $user->user_id)->first(['credits', 'share_credits']);
        $holdings = [];
        foreach ($mine['holdings'] as $h) {
            $holdings[(int) $h['company_id']] = (int) $h['units'];
        }
        return [
            'now' => World::gameNow(), 'day' => MarketHistory::today(),
            'companies' => $companies,
            'me' => ['name' => $user->login, 'credits' => (int) ($account->credits ?? 0), 'share_credits' => (int) ($account->share_credits ?? 0),
                'holdings' => $holdings,
                'orders' => array_map(fn ($o) => ['id' => $o['id'], 'company' => $o['company_id'], 'side' => $o['side'], 'units' => $o['units'],
                    'price' => $o['price'], 'peg' => $o['peg'] ?? null, 'offset_percent' => $o['offset_percent'] ?? null,
                    'out_of_range' => $o['out_of_range']], $mine['orders']),
                'bank_owes' => array_map(fn ($b) => ['company' => $b['company_id'], 'units' => $b['units'], 'price_at_sale' => $b['price_at_sale']],
                    $mine['bank_payments']),
                'dividends_30_days' => $mine['dividends_30_days']],
        ];
    }

    /** A PHP value as a Lua table constructor: arrays as lists, maps by key */
    public static function lua($v): string
    {
        if ($v === null) {
            return 'nil';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? sprintf('%.10g', $v) : '0';
        }
        if (is_string($v)) {
            return '"' . preg_replace_callback('/[^\x20-\x7e]|["\\\\]/', fn ($m) => strlen($m[0]) === 1 && ($m[0] === '"' || $m[0] === '\\')
                ? '\\' . $m[0] : implode('', array_map(fn ($b) => sprintf('\\%03d', ord($b)), str_split($m[0]))), $v) . '"';
        }
        if (is_array($v)) {
            $parts = [];
            $list = array_is_list($v);
            foreach ($v as $k => $x) {
                if ($x === null) {
                    continue;
                }
                $parts[] = $list ? self::lua($x) : '[' . self::lua(is_int($k) ? $k : (string) $k) . ']=' . self::lua($x);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return 'nil';
    }
}
