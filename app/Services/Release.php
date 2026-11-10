<?php

namespace App\Services;

use App\Agent;
use App\Command;
use App\Company;
use App\User;
use Illuminate\Support\Facades\DB;

/**
 * Releasing a script (#324 step 6): a new company with one drone in Earth
 * orbit and a starter kit. The drone is given the script, then runs its
 * launched word (the <launched> meta-command), with crash handling.
 */
class Release
{
    /** The account that owns every release now there are no player accounts (#367 step 3) */
    public const OWNER_EMAIL = 'public-forge@forge.invalid';
    public const OWNER_NAME = 'Public Forge';

    /**
     * The owner of anonymous releases: one built-in account, so the engines
     * (which expect every company and drone to have an owner) work as before.
     * $connection picks the world.
     */
    public static function owner(?string $connection = null): User
    {
        $db = $connection === null ? DB::connection() : DB::connection($connection);
        $id = $db->table('users')->where('email', self::OWNER_EMAIL)->value('user_id');
        if ($id === null) {
            $id = $db->table('users')->insertGetId(['name' => self::OWNER_NAME, 'email' => self::OWNER_EMAIL,
                'password' => bcrypt(bin2hex(random_bytes(16)))]);
        }
        $owner = new User();
        $owner->forceFill(['user_id' => (int) $id, 'name' => self::OWNER_NAME, 'email' => self::OWNER_EMAIL]);
        $owner->exists = true;
        return $owner;
    }

    /**
     * The starter scripts (#324 step 15), in examples/starters: a name and
     * what it does
     */
    /** The kits a company can start with (#380): mining and refining, or making */
    public const KITS = ['mining' => 5901, 'factory' => 5902];
    public const KIT_NAMES = [
        'mining' => 'Mining Kit: Engine, Worm and Refinery, to mine ore and refine it',
        'factory' => 'Factory Kit: Engine, Smelter, Chemical Reactor and Spider, to make alloys, parts and systems',
    ];

    /** A kit's resource ID: the mining kit unless 'factory' */
    public static function kit(?string $name): int
    {
        return self::KITS[$name] ?? self::KITS['mining'];
    }

    /** The kit each starter script needs */
    public const STARTER_KITS = ['miner' => 'mining', 'refiner' => 'mining', 'trader' => 'mining', 'maker' => 'factory'];

    /** The starter scripts, in Lua (FORTH was retired after round 38, #380) */
    public const STARTERS = [
        'miner' => 'Mines kunspar on the Moon, refines it and sells the metals to the Moon Depot.',
        'refiner' => 'Brews fuel on the Moon from yalspar and vladnium, and sells what it doesn\'t need to other drones.',
        'trader' => 'Buys metals from miners on the Moon when the price board says Mars or Earth pays enough more, and hauls them to whichever pays most.',
        'maker' => 'Buys metals on the Moon where they are cheapest, makes alloys and rods, and sells them where they pay best.',
    ];

    /** A starter script's source, or null for an unknown name ("maker" or "maker.lua") */
    public static function starter(string $name): ?string
    {
        $name = preg_replace('/\.lua$/', '', $name);
        if (!array_key_exists($name, self::STARTERS)) {
            return null;
        }
        $path = base_path("examples/starters/{$name}.lua");
        return is_readable($path) ? file_get_contents($path) : null;
    }

    /** Fuel (A1), which actions and computing burn (#324 step 8) */
    public const FUEL = 2061;

    /**
     * The warning to show for a script that uses names nothing defines or
     * sets names the game uses, or null (Lua; FORTH was retired, #380)
     */
    public static function redefinedWarning(string $script): ?string
    {
        return self::isLua($script) ? self::luaRedefinedWarning($script) : null;
    }

    /**
     * Why a script can't be released, or null: it must define launched, and
     * no line may be longer than the drone reads. The Release page and the
     * API (#380) both check this.
     */
    public static function scriptProblem(string $script): ?string
    {
        foreach (explode("\n", $script) as $i => $line) {
            if (mb_strlen($line) > \App\Command::MAX_CODE_LENGTH) {
                return 'Line ' . ($i + 1) . ' is too long: split it into shorter lines.';
            }
        }
        if (!self::isLua($script)) {
            // FORTH was retired after round 38 (#380): drones already running
            // in FORTH carry on, but new scripts are Lua
            if (preg_match('/(^|\s):\s+launched(\s|$)/', $script) === 1) {
                return 'FORTH has been retired: write your drone in Lua. Define function launched() ... end; '
                    . 'the starters and the players\' manual show how.';
            }
            return 'Your script must define the function launched ("function launched() ... end"): '
                . 'your drone runs it once it is released.';
        }
        return self::luaProblem($script);
    }

    public static function definesLaunched(string $script): bool
    {
        return self::isLua($script);
    }

    /**
     * The game's Lua names a script sets at its top level (#380 round 35: a
     * script's best_bid = ... hid the library function). The library keeps
     * working (it calls its own copies), but the script's own calls get the
     * script's value.
     */
    public static function luaRedefinedWords(string $script): array
    {
        static $known = null;
        if ($known === null) {
            $path = base_path('game-engine/src/lua_library.lua');
            preg_match_all('/^_G\.([a-z_][a-z_0-9]*) = /m', is_readable($path) ? file_get_contents($path) : '', $m);
            $known = array_flip($m[1]);
        }
        $found = [];
        preg_match_all('/^(?:function\s+([a-z_][a-z_0-9]*)\s*\(|([a-z_][a-z_0-9]*(?:\s*,\s*[a-z_][a-z_0-9]*)*)\s*=[^=])/m', $script, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $names = !empty($match[1]) ? [$match[1]] : preg_split('/\s*,\s*/', $match[2]);
            foreach ($names as $name) {
                if (isset($known[$name])) {
                    $found[$name] = true;
                }
            }
        }
        return array_keys($found);
    }

    private static function luaRedefinedWarning(string $script): ?string
    {
        $warnings = [];
        if ($undefined = self::luaUndefinedNames($script)) {
            $list = implode(', ', array_map(fn ($u) => $u['name'] . ' (line ' . $u['line'] . ')', $undefined));
            $warnings[] = 'Your script uses ' . $list . ', which ' . (count($undefined) > 1 ? 'nothing defines' : 'nothing defines')
                . ': calling ' . (count($undefined) > 1 ? 'one' : 'it') . ' crashes your drone. Check the spelling'
                . ' (case matters), or define it.';
        }
        if ($names = self::luaRedefinedWords($script)) {
            $warnings[] = 'Your script sets ' . implode(', ', $names) . ': ' . (count($names) > 1 ? 'names' : 'a name')
                . ' the game already uses. The game\'s own functions keep working, but your code gets yours from'
                . ' then on (a number where a function was expected crashes your drone). If you didn\'t mean to, rename yours.';
        }
        return $warnings ? implode(' ', $warnings) : null;
    }

    /**
     * A Lua script's syntax error, or null (#380): game-engine's lua-check
     * compiles it without running it. Null too when the checker isn't
     * installed: the drone reports the error when it loads.
     */
    public static function luaProblem(string $script): ?string
    {
        [$code, $out] = self::runLuaCheck($script);
        if ($code !== 1) {
            return null;   // compiles, or no checker: the drone reports it when it loads
        }
        // "script:12: unexpected symbol near '='" -> "Line 12: unexpected symbol near '='"
        return preg_replace('/^script:(\d+):\s*/', 'Line $1: ', $out) ?: 'Your Lua script doesn\'t compile.';
    }

    /**
     * Global names a Lua script reads that nothing defines (#380 round 35:
     * a misspelt game word only failed once the drone ran it): lua-check
     * compares them with the game's library and Lua's own. Each as
     * ['line' => n, 'name' => s].
     */
    public static function luaUndefinedNames(string $script): array
    {
        [$code, $out] = self::runLuaCheck($script);
        if ($code !== 0) {
            return [];
        }
        preg_match_all('/^warning: line (\d+): (\S+) is used but never defined$/m', $out, $m, PREG_SET_ORDER);
        return array_map(fn ($w) => ['line' => (int) $w[1], 'name' => $w[2]], $m);
    }

    /** lua-check's exit code and output for a script: [null, ''] without the checker */
    private static function runLuaCheck(string $script): array
    {
        static $cache = [];
        $key = md5($script);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $checker = config('forge.lua_check');
        if (!$checker || !is_executable($checker)) {
            return [null, ''];
        }
        $library = base_path('game-engine/src/lua_library.lua');
        $command = is_readable($library) ? [$checker, $library] : [$checker];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [null, ''];
        }
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $out = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        return $cache[$key] = [proc_close($process), $out];
    }

    /**
     * A Lua script (#380): it defines the function launched ("function
     * launched()" or "launched = function"), and no FORTH word launched.
     * A drone runs it in Lua instead of FORTH.
     */
    public static function isLua(string $script): bool
    {
        if (preg_match('/(^|\s):\s+launched(\s|$)/', $script) === 1) {
            return false;
        }
        return preg_match('/^\s*(local\s+)?function\s+launched\s*\(|^\s*launched\s*=\s*function\s*\(/m', $script) === 1;
    }

    /**
     * Splits a script into commands that fit the commands table, at line
     * ends (a word definition may span lines: the VM keeps compiling
     * across commands, as it does across an email's lines)
     */
    public static function chunks(string $script): array
    {
        $chunks = [];
        $current = '';
        foreach (preg_split('/\r\n|\r|\n/', $script) as $line) {
            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (mb_strlen($candidate) > Command::MAX_CODE_LENGTH && $current !== '') {
                $chunks[] = $current;
                $candidate = $line;
            }
            $current = $candidate;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Releases the script as a new company of this player; returns its drone.
     * $connection picks the world's database connection: null for the live
     * one (another for tools that seed a world).
     */
    public static function release(User $user, string $companyName, string $script, ?string $connection = null,
                                   ?int $copiedFrom = null, ?string $copiedWord = null, ?string $kit = null): Agent
    {
        if ($connection !== null) {
            // The models use the default connection: point it at that world
            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection($connection);
            try {
                return self::release($user, $companyName, $script, null, $copiedFrom, $copiedWord, $kit);
            } finally {
                DB::setDefaultConnection($previous);
            }
        }

        return DB::transaction(function () use ($user, $companyName, $script, $copiedFrom, $copiedWord, $kit) {
            $company = Company::create([
                'user_id' => $user->user_id,
                'name' => $companyName,
                'credits' => config('forge.starting_credits'),
            ]);

            $agent = new Agent();
            $agent->assignRandomName();
            $agent->user_id = $user->user_id;
            $agent->company_id = $company->company_id;
            $agent->object_id = Company::EARTH_OBJECT_ID;
            $agent->hull_type = Company::DEFAULT_DRONE_HULL_TYPE;
            $agent->language = self::isLua($script) ? 'lua' : 'forth';   // #380
            $agent->save();
            $agent->agent_id = $agent->id; // the key the save filled in
            // No kit (#506): credits instead, to equip itself as its script
            // chooses. The kit argument is still accepted, and ignored
            $agent->addCargo(self::FUEL, config('forge.starting_fuel'));   // its first fuel

            foreach (self::chunks($script) as $chunk) {
                Command::create([
                    'code' => $chunk,
                    'agent_id' => $agent->agent_id,
                    'subject' => 'Release',
                    'email_content' => '',
                ]);
            }
            Command::create([
                'code' => '<launched>',
                'agent_id' => $agent->agent_id,
                'subject' => 'Release',
                'email_content' => '',
            ]);

            // Where its code comes from (#324 step 16)
            Provenance::record($agent->agent_id, $company->company_id, $user->user_id, $script, $copiedFrom, $copiedWord);

            return $agent;
        });
    }
}
