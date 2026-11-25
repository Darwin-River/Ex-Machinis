<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A visitor's drone before they have an account (#1042, part of #1039).
 *
 * - The draft: the starter and code a visitor last test drove, kept in their
 *   session. Signing up keeps it (#1041): the Found page fills its form with
 *   it, and founding a startup with it clears it.
 * - A guest test drive: the visitor's code, test driven (DroneTestDrive,
 *   #1007) as a brand-new startup's drone would run it: at Earth, with the
 *   starting fuel and credits, owing the bank its grant, and nothing else.
 *   No account and no company: the questions about places and markets are
 *   asked as a stand-in startup's drone (so they see the world as a startup
 *   does), and every question about the drone's own company is answered
 *   here, as a new startup's, so nothing about the stand-in's shows.
 */
class GuestDrive
{
    /** The session key of the draft */
    public const KEY = 'guest_drone';

    /** Guest test drives a visitor (an IP address) may run an hour */
    public const PER_HOUR = 20;

    /** The questions about the drone's own company, answered here as a new startup's */
    private const OWN = [650, 651, 655, 656, 670, 671, 692, 708, 709, 711, 712, 803];

    /** The starters a guest can start from: those the Found page offers */
    public static function starters(): array
    {
        $starters = Release::STARTERS;
        unset($starters['trader']);   // a startup can't trade with other drones (#658)
        return $starters;
    }

    /** The visitor's draft, or null: {starter, script} */
    public static function draft(): ?array
    {
        $d = session(self::KEY);
        if (!is_array($d) || !is_string($d['script'] ?? null) || trim($d['script']) === '') {
            return null;
        }
        return ['starter' => array_key_exists($d['starter'] ?? '', self::starters()) ? $d['starter'] : 'miner',
            'script' => $d['script']];
    }

    public static function keep(string $starter, string $script): void
    {
        session([self::KEY => ['starter' => $starter, 'script' => $script]]);
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }

    /** May this visitor run another guest test drive now? Counts it when they may */
    public static function allow(string $ip): bool
    {
        $key = 'guest-drive:' . sha1($ip);
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return false;
        }
        RateLimiter::hit($key, 3600);
        self::count();
        return true;
    }

    /** How many guest test drives have run, all told (#1045: counts only) */
    public static function total(): int
    {
        return \Illuminate\Support\Facades\Schema::hasTable('site_counts')
            ? (int) (DB::table('site_counts')->where('name', 'guest_test_drives')->value('n') ?? 0) : 0;
    }

    private static function count(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('site_counts')) {
            return;
        }
        DB::statement('INSERT INTO site_counts (name, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1', ['guest_test_drives']);
    }

    /** The drone a guest drive asks its questions about places as: a startup's working drone, else any working drone */
    public static function standIn(): ?object
    {
        $q = fn () => DB::table('agents as a')->join('companies as c', 'c.company_id', '=', 'a.company_id')
            ->whereNull('a.wrecked_at')->whereNull('a.station_id')->whereNull('c.eliminated_at')->orderByDesc('a.agent_id');
        return $q()->where('c.startup', 1)->first(['a.agent_id', 'a.company_id'])
            ?? $q()->where(fn ($w) => $w->whereNull('c.house')->orWhere('c.house', 0))->first(['a.agent_id', 'a.company_id']);
    }

    /**
     * Test drives a guest's script as a new startup's drone. The same shape
     * as DroneTestDrive::run; stopped is 'unavailable' when there is no
     * runner or no drone in the world to stand in.
     */
    public static function run(string $script): array
    {
        $earth = \App\Company::EARTH_OBJECT_ID;
        $out = ['drone' => 0, 'drone_name' => 'your drone', 'place' => DroneTestDrive::placeName($earth),
            'lines' => [], 'actions' => [], 'said' => [], 'status' => [], 'stopped' => 'unavailable',
            'why' => 'the test drive isn\'t available here', 'error' => null, 'rounds' => 0, 'applied' => false];
        $standIn = DroneTestDrive::available() ? self::standIn() : null;
        if ($standIn === null) {
            return $out;
        }
        $static = DroneTestDrive::statics();
        $credits = (int) config('forge.starting_credits');
        $snapshot = [
            // The stand-in's id, so the questions it asks of the world see a startup's drone; its own
            // state (where it is, its credits and hold) is a new startup's
            'drone' => ['id' => (int) $standIn->agent_id, 'company' => (int) $standIn->company_id, 'location' => $earth,
                'credits' => $credits, 'holdings' => [Release::FUEL => (int) config('forge.starting_fuel')]],
            'now' => World::gameNow(),
            'epoch' => (int) round((float) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0)),
            'protocols' => $static['protocols'], 'masses' => $static['masses'], 'here' => $static['here'],
            'answers' => [],
        ];
        $drone = (int) $standIn->agent_id;
        return DroneTestDrive::drive($out, $snapshot, $script, function (array $need) use ($drone, $credits) {
            $id = (int) (str_starts_with((string) ($need[0] ?? ''), '@') ? ($need[1] ?? 0) : ($need[0] ?? 0));
            return in_array($id, self::OWN, true) ? self::own($id, $credits) : DroneTestDrive::answer($need, $drone);
        });
    }

    /** A question about the drone's own company, answered as a new startup's: rows as DroneTestDrive::answer gives them */
    private static function own(int $id, int $credits): array
    {
        $rules = DB::table('house_rules')->where('id', 1)->first();
        $named = function (array $values, array $names) {
            $row = [];
            foreach ($values as $i => $v) {
                $row[$i + 1] = $v;
            }
            foreach ($names as $i => $name) {
                $row[$name] = $values[$i];
            }
            return $row;
        };
        return match ($id) {
            650 => [$named([(int) ($rules->sales_tax_permille ?? 0), 0, 0], ['sales_tax_permille', 'day', 'all'])],
            671 => [$named([0, 0], ['parent_id', 'generation'])],
            708 => [$named([1], ['startup'])],
            709 => [$named([1, (int) ($rules->fleet_cap ?? 0), 0], ['drones', 'cap', 'coming'])],
            711 => [$named([1, $credits, Startups::grant(), 0, 0], ['startup', 'credits', 'debt', 'cap', 'to_bank'])],
            default => [],   // no trades, orders, clones, shares, obligations or events of its own yet
        };
    }
}
