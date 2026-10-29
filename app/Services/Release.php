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

    public const STARTERS = [
        'miner' => 'Mines kunspar on the Moon, refines it and sells the metals to the Moon Depot.',
        'refiner' => 'Brews fuel on the Moon from yalspar and vladnium, and sells what it doesn\'t need to other drones.',
        'trader' => 'Buys metals from miners on the Moon and hauls them to Mars, where the House pays more.',
        'maker' => 'Buys metals on the Moon where they are cheapest, makes alloys and rods, and sells them at Earth.',
    ];

    /** A starter script's source, or null for an unknown name */
    public static function starter(string $name): ?string
    {
        if (!array_key_exists($name, self::STARTERS)) {
            return null;
        }
        $path = base_path("examples/starters/{$name}.fth");
        return is_readable($path) ? file_get_contents($path) : null;
    }

    /** Fuel (A1), which actions and computing burn (#324 step 8) */
    public const FUEL = 2061;

    /**
     * The words a script defines that a drone already knows (#380): the
     * game words, report, and eForth's own (resources/drone-words.txt, kept
     * in step with the VM by make -C game-engine/machine test). A script may
     * redefine them, but it is almost always a mistake: three newcomers'
     * scripts named a word report, which silenced their drones.
     */
    public static function redefinedWords(string $script): array
    {
        static $known = null;
        if ($known === null) {
            $path = base_path('resources/drone-words.txt');
            $known = is_readable($path) ? array_flip(preg_split('/\s+/', trim(file_get_contents($path)))) : [];
        }
        // Comments out: \ to the end of the line, ( ... ) inside it
        $text = preg_replace(['/(^|\s)\\\\(\s[^\n]*)?$/m', '/(^|\s)\(\s[^)]*\)/'], ' ', $script);
        $tokens = preg_split('/\s+/', trim($text));
        $found = [];
        foreach ($tokens as $i => $token) {
            if (in_array(strtolower($token), [':', 'variable', 'constant', 'create', '2variable', '2constant'], true)
                && isset($tokens[$i + 1]) && isset($known[$tokens[$i + 1]])) {
                $found[$tokens[$i + 1]] = true;
            }
        }
        return array_keys($found);
    }

    /**
     * The warning to show for a script that uses words nothing defines or
     * redefines known ones, or null
     */
    public static function redefinedWarning(string $script): ?string
    {
        $undefined = self::undefinedWords($script);
        $warnings = [];
        if ($undefined) {
            $warnings[] = 'Your script uses ' . implode(', ', $undefined) . ', which '
                . (count($undefined) > 1 ? 'aren\'t defined' : 'isn\'t defined')
                . ' before ' . (count($undefined) > 1 ? 'they\'re' : 'it\'s') . ' used: '
                . 'a line using ' . (count($undefined) > 1 ? 'one' : 'it') . ' fails to load with -13. '
                . 'Check the spelling (case matters), or define it earlier in the script.';
        }
        if ($redefined = self::redefinedText($script)) {
            $warnings[] = $redefined;
        }
        return $warnings ? implode(' ', $warnings) : null;
    }

    /** The part of the warning about redefined words, or null */
    private static function redefinedText(string $script): ?string
    {
        $words = self::redefinedWords($script);
        if (!$words) {
            return null;
        }
        return 'Your script redefines ' . implode(', ', $words) . ': ' . (count($words) > 1 ? 'words' : 'a word')
            . ' your drone already knows. Words written after it use yours instead'
            . (in_array('report', $words, true) ? ' (report is how a looping drone sends you what it printed)' : '')
            . '. If you didn\'t mean to, rename yours.';
    }

    /**
     * The words a script uses before anything defines them (#380): neither
     * a word the drone knows nor one the script defined on an earlier line
     * or earlier on the same line. Loading such a line fails with -13 (the
     * VM's "undefined word"), and round 29's testers couldn't tell which word
     * it was. Case matters, as on the drone. Comments and strings are skipped.
     */
    public static function undefinedWords(string $script): array
    {
        static $known = null;
        if ($known === null) {
            $path = base_path('resources/drone-words.txt');
            $known = is_readable($path) ? array_flip(preg_split('/\s+/', trim(file_get_contents($path)))) : [];
        }
        $text = preg_replace([
            '/(^|\s)\\\\(\s[^\n]*)?$/m',               // \ to the end of the line
            '/(^|\s)\.?\(\s[^)]*\)/',                   // ( ... ) and .( ... )
            '/(^|\s)(\."|\$"|abort")\s[^"]*"/',         // strings
        ], ' ', $script);
        $tokens = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $defined = [];
        $found = [];
        $defining = [':', 'variable', 'constant', 'create', '2variable', '2constant'];
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (in_array(strtolower($token), $defining, true)) {
                if (isset($tokens[$i + 1])) {
                    $defined[$tokens[++$i]] = true;
                }
                continue;
            }
            if (in_array($token, ['char', '[char]'], true)) {
                $i++;
                continue;
            }
            if (isset($known[$token]) || isset($defined[$token])
                || preg_match('/^-?\d+\.?$|^\$-?[0-9a-fA-F]+$/', $token)) {
                continue;
            }
            $found[$token] = true;
        }
        return array_keys($found);
    }

    /** A script must define the word launched (case matters) */
    public static function definesLaunched(string $script): bool
    {
        return preg_match('/(^|\s):\s+launched(\s|$)/', $script) === 1;
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
     * $connection picks the world: null for the live one, 'testworld' for a
     * test run (#324 step 7).
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
            $agent->save();
            $agent->agent_id = $agent->id; // the key the save filled in
            $cargo = $agent->addCargo(self::kit($kit));                          // its kit (#380)
            DB::table('companies')->where('company_id', $company->company_id)->update(['start_kit' => self::kit($kit)]);
            $agent->addCargo(self::FUEL, config('forge.starting_fuel'), $cargo);   // its first fuel

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
