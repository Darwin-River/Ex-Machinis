<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Badges (#1048): recognition for what drones, companies and players achieve,
 * worked out from the game's own records, with no advantage in the game:
 * nothing reads them but the pages that show them.
 *
 * - The website's tick awards them every AWARD_EVERY real seconds
 *   (awardDue): each badge is one set-based query over its table, giving
 *   each subject a value, and a badge with tiers is awarded at each
 *   threshold reached (1 bronze, 2 silver, 3 gold). An award is kept for
 *   good, even if what earned it later goes (a sold share, a wrecked drone).
 * - Trades are read from a watermark (the last trade a pass saw), so a pass
 *   reads only new ones.
 * - Everyone earns them by the same rules, Claude players too, and nothing
 *   shown tells anyone apart (DV-04). A pupil's badges show to them and their
 *   teacher only, never on public pages.
 * - No farming: ownership and trading badges count only trades between
 *   different players, never a player with their own companies.
 */
class Badges
{
    /** Real seconds between award passes */
    public const AWARD_EVERY = 900;

    public const TIER_NAMES = [1 => 'bronze', 2 => 'silver', 3 => 'gold'];

    /**
     * key => [kind, name, how it is earned, tiers (thresholds, null for one), unit (a tier's thresholds' unit)]
     */
    public const CATALOG = [
        // Drones
        'first_light' => ['drone', 'First Light', 'It ran its code and acted for the first time.', null, null],
        'lift_off' => ['drone', 'Lift-off', 'Its first trip.', null, null],
        'first_strike' => ['drone', 'First Strike', 'Its first mining run.', null, null],
        'first_sale' => ['drone', 'First Sale', 'Its first sale.', null, null],
        'frontier_run' => ['drone', 'Frontier Run', 'It traded at Ceres Station, the frontier exchange.', null, null],
        'long_haul' => ['drone', 'Long Haul', 'It worked this many game days.', [30, 100, 365], 'game days'],
        'self_starter' => ['drone', 'Self-Starter', 'It crashed, restarted, and kept working a game day after.', null, null],
        'parent' => ['drone', 'Parent', 'It launched a copy of itself.', null, null],
        'dynasty' => ['drone', 'Dynasty', 'Its line reached a third generation: a copy of its copy.', null, null],
        'salvager' => ['drone', 'Salvager', 'It plundered a wreck.', null, null],
        // Companies
        'founded' => ['company', 'Founded', 'It was founded.', null, null],
        'matured' => ['company', 'Matured', 'It repaid the bank and its shares were claimed.', null, null],
        'speed_run' => ['company', 'Speed Run', 'It matured faster than its starter usually does.', null, null],
        'rewritten' => ['company', 'Rewritten', 'Its code was pushed this many times.', [5, 25], 'pushes'],
        'fleet' => ['company', 'Fleet', 'It had this many working drones at once.', [3, 8, 20], 'drones'],
        'dividend_payer' => ['company', 'Dividend Payer', 'It paid dividends on this many game days.', [7, 30, 100], 'game days'],
        'gone_public' => ['company', 'Gone Public', 'Its first share was sold.', null, null],
        'wide_ownership' => ['company', 'Wide Ownership', 'It has 10 or more shareholders.', null, null],
        'good_borrower' => ['company', 'Good Borrower', 'It repaid a loan in full, on time.', null, null],
        'contract_kept' => ['company', 'Contract Kept', 'It delivered this many contracts in full.', [5, 25], 'contracts'],
        'diversified' => ['company', 'Diversified', 'It sold 5 different goods in a game week.', null, null],
        'maker' => ['company', 'Maker', 'It sold a part or a system (a tier 3 or 4 good).', null, null],
        'remembered' => ['company', 'Remembered', 'It ended after 100 game days or more.', null, null],
        'ancestor' => ['company', 'Ancestor', 'Another company\'s code descends from its code.', null, null],
        'open_book' => ['company', 'Open Book', 'Its code was copied into another company\'s release.', null, null],
        // Players
        'test_pilot' => ['player', 'Test Pilot', 'A first test drive.', null, null],
        'founder' => ['player', 'Founder', 'A first startup.', null, null],
        'fixer' => ['player', 'Fixer', 'A first fix pushed to a running startup.', null, null],
        'first_crash' => ['player', 'First Crash', 'A drone\'s first crash. Break, learn, repeat.', null, null],
        'phoenix' => ['player', 'Phoenix', 'Matured a company after closing or losing a startup.', null, null],
        'shareholder' => ['player', 'Shareholder', 'Claimed a company\'s shares.', null, null],
        'investor' => ['player', 'Investor', 'Bought a share in another player\'s company.', null, null],
        'lender' => ['player', 'Lender', 'Backed a loan.', null, null],
        'repaid' => ['player', 'Repaid', 'A loan they backed was repaid in full.', null, null],
        'portfolio' => ['player', 'Portfolio', 'Holds shares in 5 other companies.', null, null],
        'five_outright' => ['player', 'Five Outright', 'Owned the most companies outright one may: five.', null, null],
        'market_maker' => ['player', 'Market Maker', 'Earned the resting-order rebate this many times.', [10, 50], 'rebates'],
        'scripted_trader' => ['player', 'Scripted Trader', 'Their trading script placed an order.', null, null],
        'climber' => ['player', 'Climber', 'Climbed the whole ladder: founded, fixed, matured, invested and lent.', null, null],
        'long_game' => ['player', 'Long Game', 'Owns a company that paid dividends on 100 game days.', null, null],
        'teacher' => ['player', 'Teacher', 'A pupil in their class matured a company.', null, null],
    ];

    /** From the tick: an award pass, at most every AWARD_EVERY real seconds */
    public static function awardDue(): int
    {
        if (!Schema::hasTable('badges') || !Cache::add('badges-awarding', 1, self::AWARD_EVERY)) {
            return 0;
        }
        return self::awardAll();
    }

    /** Every badge, now: how many awards were new */
    public static function awardAll(): int
    {
        $new = 0;
        foreach (self::values() as $key => $sql) {
            [$kind, , , $tiers] = self::CATALOG[$key];
            foreach ($tiers ?? [1] as $i => $threshold) {
                $new += DB::affectingStatement(
                    "INSERT IGNORE INTO badges (kind, subject_id, badge, tier, game_t) SELECT ?, v.subject, ?, ?, ? FROM ($sql) AS v WHERE v.subject IS NOT NULL AND v.value >= ?",
                    [$kind, $key, $tiers ? $i + 1 : 1, World::gameNow(), $tiers ? $threshold : 1]);
            }
        }
        $new += self::fromTrades();
        $new += self::speedRuns();
        $new += self::fiveOutright();
        return $new;
    }

    /** Awards one badge now (a test drive, say), if not already had: true when new */
    public static function award(string $kind, int $subject, string $key, int $tier = 1): bool
    {
        if (!Schema::hasTable('badges') || !isset(self::CATALOG[$key])) {
            return false;
        }
        return DB::affectingStatement('INSERT IGNORE INTO badges (kind, subject_id, badge, tier, game_t) VALUES (?, ?, ?, ?, ?)',
            [$kind, $subject, $key, $tier, World::gameNow()]) > 0;
    }

    /**
     * Each badge's query (not those read from trades): SELECT subject, value.
     * Players are people's accounts and Claude players alike (a login), never the game's own.
     */
    private static function values(): array
    {
        $now = World::gameNow();
        $day = 86400;
        $players = 'SELECT user_id FROM users WHERE login IS NOT NULL';
        return [
            // Drones
            'first_light' => 'SELECT drone AS subject, 1 AS value FROM drone_days WHERE actions > 0 GROUP BY drone',
            'lift_off' => 'SELECT a.agent_id AS subject, 1 AS value FROM agents a JOIN companies c ON c.company_id = a.company_id '
                . 'WHERE a.station_id IS NULL AND a.arrived_t IS NOT NULL AND (a.object_id <> 3 OR a.arrived_t > IFNULL(c.founded_t, 0) + 60)',
            'first_strike' => 'SELECT DISTINCT drone AS subject, 1 AS value FROM mining_runs',
            'long_haul' => 'SELECT drone AS subject, COUNT(*) AS value FROM drone_days WHERE actions > 0 GROUP BY drone',
            'self_starter' => "SELECT k.drone AS subject, 1 AS value FROM drone_crashes k JOIN drone_days d ON d.drone = k.drone "
                . "GROUP BY k.drone HAVING MAX(d.last_t) > MIN(k.game_t) + $day",
            'parent' => 'SELECT DISTINCT parent_id AS subject, 1 AS value FROM agents WHERE parent_id IS NOT NULL',
            'dynasty' => 'SELECT DISTINCT p.parent_id AS subject, 1 AS value FROM agents c JOIN agents p ON p.agent_id = c.parent_id WHERE p.parent_id IS NOT NULL',
            'salvager' => 'SELECT DISTINCT plundered_by AS subject, 1 AS value FROM agents WHERE plundered_by IS NOT NULL',
            // Companies
            'founded' => 'SELECT company_id AS subject, 1 AS value FROM companies WHERE owner_id IS NOT NULL',
            'matured' => 'SELECT company_id AS subject, 1 AS value FROM companies WHERE matured_t IS NOT NULL',
            'rewritten' => 'SELECT company_id AS subject, COUNT(*) AS value FROM code_pushes GROUP BY company_id',
            'fleet' => 'SELECT company_id AS subject, COUNT(*) AS value FROM agents WHERE station_id IS NULL AND wrecked_at IS NULL GROUP BY company_id',
            'dividend_payer' => 'SELECT company_id AS subject, COUNT(DISTINCT day) AS value FROM player_dividends WHERE amount > 0 GROUP BY company_id',
            'gone_public' => 'SELECT DISTINCT company_id AS subject, 1 AS value FROM player_share_trades WHERE seller_id IS NOT NULL'
                . (Schema::hasTable('bank_payments') ? ' UNION SELECT DISTINCT company_id, 1 FROM bank_payments' : ''),
            'wide_ownership' => 'SELECT company_id AS subject, COUNT(DISTINCT user_id) AS value FROM player_shares WHERE units > 0 GROUP BY company_id HAVING value >= 10',
            'good_borrower' => 'SELECT DISTINCT company_id AS subject, 1 AS value FROM loans WHERE closed_t IS NOT NULL AND closed_t <= due_t '
                . 'AND defaulted_t IS NULL AND repaid >= owed AND owed > 0',
            'contract_kept' => "SELECT seller_company AS subject, COUNT(*) AS value FROM contracts WHERE outcome = 'kept' AND seller_company IS NOT NULL GROUP BY seller_company",
            'remembered' => "SELECT company_id AS subject, 1 AS value FROM companies WHERE eliminated_at IS NOT NULL AND founded_t IS NOT NULL "
                . "AND eliminated_at - founded_t >= 100 * $day",
            'ancestor' => 'SELECT DISTINCT pr.company_id AS subject, 1 AS value FROM code_parents cp JOIN code_revisions r ON r.id = cp.revision_id '
                . 'JOIN code_revisions pr ON pr.id = cp.parent_id WHERE pr.company_id <> r.company_id',
            'open_book' => 'SELECT DISTINCT src.company_id AS subject, 1 AS value FROM releases r JOIN agents src ON src.agent_id = r.copied_from '
                . 'WHERE r.copied_from IS NOT NULL AND src.company_id <> r.company_id',
            // Players
            'founder' => "SELECT DISTINCT owner_id AS subject, 1 AS value FROM companies WHERE owner_id IN ($players)",
            'fixer' => "SELECT DISTINCT c.owner_id AS subject, 1 AS value FROM code_pushes p JOIN companies c ON c.company_id = p.company_id WHERE c.owner_id IN ($players)",
            'first_crash' => "SELECT DISTINCT c.owner_id AS subject, 1 AS value FROM drone_crashes k JOIN agents a ON a.agent_id = k.drone "
                . "JOIN companies c ON c.company_id = a.company_id WHERE c.owner_id IN ($players)",
            'phoenix' => "SELECT DISTINCT m.owner_id AS subject, 1 AS value FROM companies m JOIN companies f ON f.owner_id = m.owner_id "
                . "AND f.company_id <> m.company_id AND f.startup = 1 AND f.eliminated_at IS NOT NULL AND f.eliminated_at < m.matured_t "
                . "WHERE m.matured_t IS NOT NULL AND m.owner_id IN ($players)",
            'shareholder' => "SELECT DISTINCT owner_id AS subject, 1 AS value FROM companies WHERE matured_t IS NOT NULL AND owner_id IN ($players)",
            'investor' => 'SELECT DISTINCT t.buyer_id AS subject, 1 AS value FROM player_share_trades t JOIN companies c ON c.company_id = t.company_id '
                . 'WHERE t.buyer_id IS NOT NULL AND (c.owner_id IS NULL OR c.owner_id <> t.buyer_id) AND (t.seller_id IS NULL OR t.seller_id <> t.buyer_id)',
            'lender' => 'SELECT DISTINCT l.user_id AS subject, 1 AS value FROM loans l JOIN companies c ON c.company_id = l.company_id WHERE c.owner_id IS NULL OR c.owner_id <> l.user_id',
            'repaid' => 'SELECT DISTINCT user_id AS subject, 1 AS value FROM loans WHERE closed_t IS NOT NULL AND defaulted_t IS NULL AND repaid >= owed AND owed > 0',
            'portfolio' => 'SELECT s.user_id AS subject, COUNT(DISTINCT s.company_id) AS value FROM player_shares s JOIN companies c ON c.company_id = s.company_id '
                . 'WHERE s.units > 0 AND (c.owner_id IS NULL OR c.owner_id <> s.user_id) GROUP BY s.user_id HAVING value >= 5',
            'market_maker' => 'SELECT rebate_user_id AS subject, COUNT(*) AS value FROM player_share_trades WHERE rebate > 0 AND rebate_user_id IS NOT NULL '
                . 'AND seller_id IS NOT NULL AND buyer_id IS NOT NULL AND seller_id <> buyer_id GROUP BY rebate_user_id',
            'scripted_trader' => 'SELECT DISTINCT user_id AS subject, 1 AS value FROM player_share_orders WHERE by_script = 1',
            'long_game' => 'SELECT c.owner_id AS subject, MAX(d.days) AS value FROM companies c JOIN (SELECT company_id, COUNT(DISTINCT day) AS days '
                . "FROM player_dividends WHERE amount > 0 GROUP BY company_id) d ON d.company_id = c.company_id WHERE c.owner_id IN ($players) "
                . 'GROUP BY c.owner_id HAVING value >= 100',
            'teacher' => 'SELECT DISTINCT g.teacher_id AS subject, 1 AS value FROM class_groups g JOIN users u ON u.class_group_id = g.id '
                . 'JOIN companies c ON c.owner_id = u.user_id WHERE c.matured_t IS NOT NULL',
            'climber' => "SELECT b.subject_id AS subject, 1 AS value FROM badges b WHERE b.kind = 'player' "
                . "AND b.badge IN ('founder', 'fixer', 'shareholder', 'investor', 'lender') GROUP BY b.subject_id HAVING COUNT(DISTINCT b.badge) = 5",
        ];
    }

    /** The badges read from trades, from the last trade a pass saw: First Sale, Frontier Run, Maker, Diversified */
    private static function fromTrades(): int
    {
        $from = (int) (DB::table('site_counts')->where('name', 'badges_trade_watermark')->value('n') ?? 0);
        $to = (int) (DB::table('trades')->max('id') ?? 0);
        if ($to <= $from) {
            return 0;
        }
        $to = min($to, $from + 200000);   // a long backlog over several passes
        $now = World::gameNow();
        $range = 't.id > ? AND t.id <= ?';
        $new = 0;
        $insert = function (string $kind, string $key, string $select, array $bind) use (&$new, $now) {
            $new += DB::affectingStatement("INSERT IGNORE INTO badges (kind, subject_id, badge, tier, game_t) SELECT ?, v.subject, ?, 1, ? FROM ($select) AS v WHERE v.subject IS NOT NULL",
                array_merge([$kind, $key, $now], $bind));
        };
        $insert('drone', 'first_sale', "SELECT DISTINCT t.seller AS subject FROM trades t WHERE $range AND t.units > 0", [$from, $to]);
        $insert('drone', 'frontier_run', "SELECT DISTINCT t.seller AS subject FROM trades t WHERE $range AND t.location = 191 "
            . "UNION SELECT DISTINCT t.buyer FROM trades t WHERE $range AND t.location = 191", [$from, $to, $from, $to]);
        $insert('company', 'maker', "SELECT DISTINCT a.company_id AS subject FROM trades t JOIN agents a ON a.agent_id = t.seller "
            . "WHERE $range AND t.resource BETWEEN 3000 AND 4999 AND t.units > 0", [$from, $to]);
        // Five different goods sold in a game week, among the companies that sold in this stretch
        $insert('company', 'diversified', "SELECT a.company_id AS subject FROM trades t JOIN agents a ON a.agent_id = t.seller "
            . "WHERE t.game_t > ? AND t.units > 0 AND a.company_id IN (SELECT DISTINCT a2.company_id FROM trades t2 JOIN agents a2 ON a2.agent_id = t2.seller WHERE t2.id > ? AND t2.id <= ?) "
            . "GROUP BY a.company_id HAVING COUNT(DISTINCT t.resource) >= 5", [$now - 7 * 86400, $from, $to]);
        DB::statement('INSERT INTO site_counts (name, n) VALUES (?, ?) ON DUPLICATE KEY UPDATE n = VALUES(n)', ['badges_trade_watermark', $to]);
        return $new;
    }

    /** Speed Run: matured in fewer game days than the fast end of its starter's usual range (STARTER_CARDS) */
    private static function speedRuns(): int
    {
        $new = 0;
        $rows = DB::table('companies AS c')->whereNotNull('c.matured_t')->whereNotNull('c.founded_t')
            ->whereNotExists(fn ($q) => $q->from('badges AS b')->where('b.kind', 'company')->where('b.badge', 'speed_run')->whereColumn('b.subject_id', 'c.company_id'))
            ->get(['c.company_id', 'c.founded_t', 'c.matured_t']);
        foreach ($rows as $c) {
            $source = DB::table('releases')->where('company_id', $c->company_id)->orderBy('id')->value('source');
            $starter = FirstMission::starter((string) $source);
            $days = $starter ? (Release::STARTER_CARDS[$starter]['days'] ?? null) : null;
            if ($days && ((int) $c->matured_t - (int) $c->founded_t) < $days[0] * 86400 && self::award('company', (int) $c->company_id, 'speed_run')) {
                $new++;
            }
        }
        return $new;
    }

    /** Five Outright: owns the most companies outright one may, now */
    private static function fiveOutright(): int
    {
        $max = (int) config('forge.startup_max');
        $new = 0;
        $owners = DB::table('companies')->whereNull('eliminated_at')->whereNotNull('owner_id')->groupBy('owner_id')
            ->havingRaw('COUNT(*) >= ?', [$max])->pluck('owner_id');
        foreach ($owners as $owner) {
            if (Startups::slotsInUse((int) $owner) >= $max && self::award('player', (int) $owner, 'five_outright')) {
                $new++;
            }
        }
        return $new;
    }

    /**
     * A subject's badges, best tier of each, newest first:
     * [{key, name, text, tier, tier_name, threshold, unit, game_t, when}]
     */
    public static function of(string $kind, int $subject): array
    {
        if (!Schema::hasTable('badges')) {
            return [];
        }
        $rows = DB::table('badges')->where('kind', $kind)->where('subject_id', $subject)
            ->groupBy('badge')->selectRaw('badge, MAX(tier) AS tier, MAX(game_t) AS game_t')->orderByDesc('game_t')->get();
        $out = [];
        foreach ($rows as $r) {
            if (!isset(self::CATALOG[$r->badge])) {
                continue;
            }
            [, $name, $text, $tiers, $unit] = self::CATALOG[$r->badge];
            $tier = (int) $r->tier;
            $out[] = ['key' => $r->badge, 'name' => $name, 'text' => $text, 'tier' => $tier,
                'tier_name' => $tiers ? self::TIER_NAMES[$tier] : null, 'threshold' => $tiers[$tier - 1] ?? null, 'unit' => $unit,
                'game_t' => (int) $r->game_t, 'when' => DronePage::when((int) $r->game_t)];
        }
        return $out;
    }

    /** What each kind of badge is earned by, for the Badges page's headings (#1055) */
    public const GROUPS = ['drone' => 'Drones', 'company' => 'Companies', 'player' => 'Players'];

    /**
     * Every badge for the Badges page (#1055), grouped by kind in the
     * catalogue's order: kind => [{key, name, text, tiers: [{tier, name,
     * threshold, earned}], unit, earned}]. `earned` counts how many have it
     * (a tier's, how many reached that tier): counts only, naming no one.
     */
    public static function catalog(): array
    {
        $counts = [];
        if (Schema::hasTable('badges')) {
            foreach (DB::table('badges')->groupBy('badge', 'tier')->selectRaw('badge, tier, COUNT(*) AS n')->get() as $r) {
                $counts[$r->badge][(int) $r->tier] = (int) $r->n;
            }
        }
        $out = array_fill_keys(array_keys(self::GROUPS), []);
        foreach (self::CATALOG as $key => [$kind, $name, $text, $tiers, $unit]) {
            $out[$kind][] = ['key' => $key, 'name' => $name, 'text' => $text, 'unit' => $unit,
                'tiers' => $tiers ? array_map(fn ($t, $i) => ['tier' => $i + 1, 'name' => self::TIER_NAMES[$i + 1], 'threshold' => $t,
                    'earned' => $counts[$key][$i + 1] ?? 0], $tiers, array_keys($tiers)) : [],
                'earned' => $counts[$key][1] ?? 0];
        }
        return $out;
    }

    /** A badge's address on the Badges page */
    public static function url(string $key): string
    {
        return url('badges') . '#' . $key;
    }

    /**
     * May this viewer see these badges? A pupil's (or their companies' and
     * drones') show only to the pupil and their teacher: never on public pages
     */
    public static function visible(?int $owner, ?\App\User $viewer): bool
    {
        if ($owner === null) {
            return true;
        }
        $group = DB::table('users')->where('user_id', $owner)->value('class_group_id');
        if ($group === null) {
            return true;
        }
        if (!$viewer) {
            return false;
        }
        return (int) $viewer->user_id === $owner
            || (int) DB::table('class_groups')->where('id', $group)->value('teacher_id') === (int) $viewer->user_id;
    }

    /** Badges awarded to a player, their companies and drones since game time $since: for "since you last looked" */
    public static function since(int $user, int $since): array
    {
        if (!Schema::hasTable('badges')) {
            return [];
        }
        $companies = DB::table('companies')->where('owner_id', $user)->pluck('company_id')->all();
        $drones = $companies ? DB::table('agents')->whereIn('company_id', $companies)->pluck('agent_id')->all() : [];
        return DB::table('badges')->where('game_t', '>', $since)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('kind', 'player')->where('subject_id', $user))
                ->orWhere(fn ($w) => $w->where('kind', 'company')->whereIn('subject_id', $companies ?: [0]))
                ->orWhere(fn ($w) => $w->where('kind', 'drone')->whereIn('subject_id', $drones ?: [0])))
            ->orderByDesc('game_t')->limit(20)->get()->all();
    }
}
