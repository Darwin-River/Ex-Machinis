<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A test drive of a drone script (#1007): runs a script read-only against a
 * snapshot of the world as it is now (one of the company's drones, its
 * company and the market), and says what the drone would do. No engine and
 * no database writes: only the script's own stubbed actions change the
 * snapshot.
 *
 * bin/lua-drive (game-engine/src/lua_drive.c) runs the script with the
 * drones' real library and stubs for perform, query and the other
 * built-ins. The snapshot starts with the drone's state and every
 * protocol's effects; a query it hasn't the answer to stops the run with a
 * `need` line, which is answered here (LiveQuery::lua, as that drone would
 * ask it) and the run started again, up to MAX_ROUNDS times. A run is
 * deterministic (math.random is seeded), so each round replays the last
 * and goes further.
 */
class DroneTestDrive
{
    /** Runs of lua-drive a test drive may take (each answers one more question) */
    public const MAX_ROUNDS = 40;
    /** Real seconds all the rounds may take */
    public const BUDGET = 20;

    /** Why a drive stopped, in words */
    private const WHY = [
        'finished' => 'its launched() returned',
        'actions' => 'it reached 50 actions, as far as a test drive goes',
        'day' => 'it reached a game day of actions, as far as a test drive goes',
        'instructions' => 'it ran too long without acting (more instructions than a test drive allows)',
        'error' => 'the script failed',
        'questions' => 'it asked more questions of the world than a test drive answers',
        'unavailable' => 'the test drive isn\'t available here',
    ];

    public static function available(): bool
    {
        $runner = config('forge.lua_drive');
        return $runner && is_executable($runner) && is_readable(self::library());
    }

    private static function library(): string
    {
        return base_path('game-engine/src/lua_library.lua');
    }

    /** May this player test drive the company's drones? Its owner only: the snapshot shows its drones' holdings */
    public static function mayDrive(?User $user, int $company): bool
    {
        return $user !== null && Ownership::ownedBy($company, (int) $user->user_id);
    }

    /** The drone a drive uses: $drone if it is one of the company's working drones, else its first */
    public static function drone(int $company, ?int $drone = null): ?object
    {
        $q = DB::table('agents')->where('company_id', $company)->whereNull('wrecked_at')->whereNull('station_id')
            ->orderBy('agent_id');
        if ($drone !== null) {
            $q->where('agent_id', $drone);
        }
        return $q->first(['agent_id', 'name', 'company_id', 'object_id']);
    }

    /**
     * Test drives $script on one of the company's drones (its first working
     * one, or $drone). Null when the company has no such drone. Otherwise:
     * drone, drone_name, place (where it starts), lines (what it would do,
     * said and set as its status, in order), actions (each {at, text,
     * result}), said, status, stopped (finished, actions, day,
     * instructions, error or questions), why (in words), error, rounds and
     * applied (always false).
     */
    public static function run(int $company, string $script, ?int $drone = null): ?array
    {
        $agent = self::drone($company, $drone);
        if (!$agent) {
            return null;
        }
        $out = ['drone' => (int) $agent->agent_id, 'drone_name' => (string) $agent->name,
            'place' => self::placeName((int) $agent->object_id), 'lines' => [], 'actions' => [], 'said' => [], 'status' => [],
            'stopped' => 'unavailable', 'why' => self::WHY['unavailable'], 'error' => null, 'rounds' => 0, 'applied' => false];
        if (!self::available()) {
            return $out;
        }

        $snapshot = self::snapshot($agent);
        $dir = sys_get_temp_dir();
        $files = ['script' => tempnam($dir, 'drive'), 'snapshot' => tempnam($dir, 'snap')];
        file_put_contents($files['script'], $script);
        $start = microtime(true);
        $lines = [];
        $failed = null;
        try {
            for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
                $out['rounds'] = $round;
                file_put_contents($files['snapshot'], 'return ' . TradeScripts::lua($snapshot));
                [$lines, $failed] = TradeScripts::sandbox([config('forge.lua_drive'), self::library(), $files['script'], $files['snapshot']]);
                $need = null;
                foreach ($lines as $line) {
                    if (str_starts_with($line, "need\t")) {
                        $need = array_slice(explode("\t", $line), 1);
                    }
                }
                if ($need === null || $failed !== null || microtime(true) - $start > self::BUDGET) {
                    break;
                }
                $snapshot['answers'][implode('|', $need)] = self::answer($need, (int) $agent->agent_id);
            }
        } finally {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        return self::describe($out, $lines, $failed, $snapshot);
    }

    /** The answer to a need line's question: rows, or a negative code */
    private static function answer(array $need, int $drone): array|int
    {
        $place = null;
        if (isset($need[0]) && str_starts_with($need[0], '@')) {
            $place = (int) substr(array_shift($need), 1);
        }
        $id = (int) array_shift($need);
        $args = array_map(fn ($a) => preg_match('/^-?\d+$/', $a) ? (int) $a : $a, $need);
        $r = LiveQuery::lua($id, $drone, $args, $place);
        if ($r['code'] < 0) {
            return $r['code'];
        }
        // Each row its values in order, and by name (the first of a name)
        return array_map(function ($row) {
            $t = [];
            foreach ($row as $i => $f) {
                $t[$i + 1] = $f['value'];
            }
            foreach ($row as $f) {
                if ($f['name'] !== '' && !array_key_exists($f['name'], $t)) {
                    $t[$f['name']] = $f['value'];
                }
            }
            return $t;
        }, $r['rows']);
    }

    /** The world as the drone sees it now: its state, the protocols, the resources' masses */
    public static function snapshot(object $agent): array
    {
        $id = (int) $agent->agent_id;
        $holdings = [];
        foreach (DB::table('holdings')->where('drone', $id)->groupBy('resource')
                     ->selectRaw('resource, SUM(units) AS units')->get() as $h) {
            if ((int) $h->units !== 0) {
                $holdings[(int) $h->resource] = (int) $h->units;
            }
        }
        $static = self::statics();
        return [
            'drone' => ['id' => $id, 'company' => (int) $agent->company_id, 'location' => (int) $agent->object_id,
                'credits' => (int) (DB::table('companies')->where('company_id', $agent->company_id)->value('credits') ?? 0),
                'holdings' => $holdings],
            'now' => World::gameNow(),
            'epoch' => (int) round((float) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0)),
            'protocols' => $static['protocols'], 'masses' => $static['masses'], 'here' => $static['here'],
            'answers' => [],
        ];
    }

    /** What changes only with the game data: every protocol's effects, masses, the "here" queries (cached) */
    public static function statics(): array
    {
        return Cache::remember('drone-test-drive-statics', 600, fn () => [
            'protocols' => self::protocols(),
            'masses' => DB::table('resources')->pluck('mass', 'id')->map(fn ($m) => (int) $m)->all(),
            'here' => array_fill_keys(LiveQuery::hereQueries(), true),
        ]);
    }

    public static function forget(): void
    {
        Cache::forget('drone-test-drive-statics');
    }

    /**
     * Every protocol as lua-drive takes it: seconds (its longest effect),
     * inputs and outputs (the acting drone's fixed resources), mined (an
     * output times the place's yield), tools (locked for the action),
     * params (a resource named by a parameter: k-th from the top), and
     * travel, wait, multiplier
     */
    public static function protocols(): array
    {
        $out = [];
        foreach (DB::table('protocols')->get(['id', 'multiplier']) as $p) {
            $out[(int) $p->id] = ['seconds' => 0, 'multiplier' => (bool) $p->multiplier];
        }
        foreach (DB::table('location_effects')->pluck('protocol') as $p) {
            if (isset($out[(int) $p])) {
                $out[(int) $p]['travel'] = true;
            }
        }
        foreach (DB::table('resource_effects')->orderBy('id')->get() as $e) {
            $p = (int) $e->protocol;
            if (!isset($out[$p])) {
                continue;
            }
            $out[$p]['seconds'] = max($out[$p]['seconds'], (int) $e->time);
            $type = (int) $e->event_type;
            $r = (int) $e->resource;
            $q = (int) $e->quantity;
            if ($type === 12) {
                $out[$p]['wait'] = true;
            }
            if ((int) $e->drone !== 0) {
                continue;   // another drone's: the order's, or the drone handed to
            }
            if ($type === 15 && $r > 0) {
                $out[$p]['tools'][$r] = true;
            }
            if ($type !== 1 && $type !== 2) {
                continue;
            }
            $outward = !(int) $e->deplete;
            if ($r < 0) {
                $out[$p]['params'][] = ['k' => -$r, 'n' => $q, 'out' => $outward];
            } elseif ($r > 0 && $q > 0) {
                $side = $outward ? 'outputs' : 'inputs';
                $out[$p][$side][$r] = ($out[$p][$side][$r] ?? 0) + $q;
                if ($outward && (int) $e->abundancies) {
                    $out[$p]['mined'][$r] = true;
                }
            }
        }
        return $out;
    }

    /** lua-drive's lines in plain words */
    private static function describe(array $out, array $lines, ?string $failed, array $snapshot): array
    {
        $protocols = DB::table('protocols')->pluck('name', 'id')->all();
        $resources = DB::table('resources')->pluck('name', 'id')->all();
        $res = fn ($r) => (string) ($resources[(int) $r] ?? "resource $r");
        $place = (int) $snapshot['drone']['location'];
        $info = $snapshot['protocols'];
        $stopped = null;
        foreach ($lines as $line) {
            $f = explode("\t", $line);
            switch ($f[0]) {
                case 'action':
                    [$t, $what, $args, $result, $seconds, $credits, $changes] = array_pad(array_slice($f, 1), 7, '');
                    $args = $args === '' ? [] : explode(' ', $args);
                    $text = self::action($what, $args, (int) $result, (int) $seconds, (int) $credits,
                        self::changes($changes), $place, $protocols, $res, $info);
                    if ((int) $result === 0 && !empty($info[(int) $what]['travel']) && $args) {
                        $place = (int) end($args);   // where it would be from here on
                    }
                    $out['actions'][] = ['at' => (int) $t, 'text' => $text, 'result' => (int) $result];
                    $out['lines'][] = self::clock((int) $t) . ' ' . $text;
                    break;
                case 'say':
                    $out['said'][] = $f[1] ?? '';
                    $out['lines'][] = 'it would say: ' . ($f[1] ?? '');
                    break;
                case 'status':
                    $out['status'][] = $f[1] ?? '';
                    $out['lines'][] = 'its status would read: ' . ($f[1] ?? '');
                    break;
                case 'error':
                    $out['error'] = $f[1] ?? 'it failed';
                    $stopped = 'error';
                    break;
                case 'end':
                    $stopped = $f[1] ?? 'finished';
                    break;
                case 'need':
                    $stopped = 'questions';
                    break;
            }
        }
        if ($stopped === null) {
            $stopped = 'error';
            $out['error'] = $failed ?? 'the test drive ended without saying why';
        }
        $out['stopped'] = $stopped;
        $out['why'] = self::WHY[$stopped] ?? $stopped;
        return $out;
    }

    /** "2061:-5 1100:+4" as [resource => change] */
    private static function changes(string $changes): array
    {
        $out = [];
        foreach (array_filter(explode(' ', $changes)) as $c) {
            [$r, $n] = array_pad(explode(':', $c), 2, '0');
            $out[(int) $r] = (int) $n;
        }
        return $out;
    }

    /** One action in words: "would mine 20 Kunspar at the Moon (1 game hour)" */
    private static function action(string $what, array $args, int $result, int $seconds, int $credits, array $changes, int $place,
                                   array $protocols, \Closure $res, array $info): string
    {
        if (!ctype_digit(ltrim($what, '-'))) {
            return "would call $what(" . implode(', ', $args) . ')';   // a finance word: recorded, nothing changed
        }
        $p = (int) $what;
        $name = (string) ($protocols[$p] ?? "protocol $p");
        $last = (int) ($args ? end($args) : 0);
        // The goods (not fuel) it would gain ($sign 1) or give up (-1), in words
        $goods = function (array $c, int $sign) use ($res) {
            $words = [];
            foreach ($c as $r => $n) {
                if ($r !== Release::FUEL && $n * $sign > 0) {
                    $words[] = abs($n) . ' ' . $res($r);
                }
            }
            return implode(', ', $words);
        };
        $made = $goods($changes, 1);
        $used = $goods($changes, -1);
        $n = (int) ($args[0] ?? 0);
        $resource = $args[1] ?? null;
        $text = match (true) {
            !empty($info[$p]['travel']) => 'fly to ' . self::placeName($last),
            !empty($info[$p]['wait']) => 'wait ' . self::duration(60 * $n),
            !empty($info[$p]['mined']) => 'mine ' . ($made !== '' ? $made : 'nothing') . ' at ' . self::placeName($place),
            $p === 11 && $credits < 0 => "buy $n " . $res($resource) . ' for ' . number_format(-$credits) . ' credits at ' . self::placeName($place),
            $p === 10 && $credits > 0 => "sell $n " . $res($resource) . ' for ' . number_format($credits) . ' credits at ' . self::placeName($place),
            $p === 11 => "pull $n " . $res($resource) . " from drone or order $last",
            $p === 10 => "push $n " . $res($resource) . " to drone or order $last",
            $p === 7 => 'post a sell order for ' . $res($last) . ' at ' . (int) ($args[0] ?? 0) . ' a unit, keeping ' . (int) ($args[1] ?? 0),
            $p === 8 => 'post a buy order for ' . $res($last) . ' at ' . (int) ($args[0] ?? 0) . ' a unit, up to ' . (int) ($args[1] ?? 0) . ' aboard',
            default => lcfirst($name) . ($made !== '' || $used !== '' ? ' (' . trim(($used !== '' ? "using $used" : '')
                . ($made !== '' ? ($used !== '' ? ', ' : '') . "making $made" : '')) . ')' : ''),
        };
        if ($result !== 0) {
            return "would try to $text: refused ($result" . self::code($result) . ')';
        }
        $fuel = -($changes[Release::FUEL] ?? 0);
        $extra = array_filter([$seconds > 0 && empty($info[$p]['wait']) ? self::duration($seconds) : null, $fuel > 0 ? "$fuel fuel" : null]);
        return "would $text" . ($extra ? ' (' . implode(', ', $extra) . ')' : '');
    }

    private static function code(int $code): string
    {
        return match ($code) {
            -2 => ': it doesn\'t hold what that takes',
            -4 => ': not enough credits',
            -11 => ': no such protocol',
            -12 => ': refused',
            default => '',
        };
    }

    /** Game seconds into the drive as h:mm */
    private static function clock(int $t): string
    {
        return sprintf('%d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60));
    }

    private static function duration(int $seconds): string
    {
        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            $h = intdiv($seconds, 3600);
            return "$h game " . ($h === 1 ? 'hour' : 'hours');
        }
        if ($seconds >= 3600) {
            return round($seconds / 3600, 1) . ' game hours';
        }
        $m = max(1, intdiv($seconds, 60));
        return $seconds < 60 ? "$seconds game seconds" : "$m game " . ($m === 1 ? 'minute' : 'minutes');
    }

    private static function placeName(int $place): string
    {
        static $names = [];
        $names[$place] ??= (string) (DB::table('objects')->where('object_id', $place)->value('object_name') ?? "place $place");
        return in_array($names[$place], ['Moon', 'Sun'], true) ? 'the ' . $names[$place] : $names[$place];
    }
}
