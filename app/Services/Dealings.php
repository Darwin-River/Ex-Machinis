<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Who a company deals with (#639, step 4 of the new rules in #635): the other
 * companies (not the House) its drones have traded with or plundered, or that
 * plundered its wrecks, by name. A deal between two companies with the same
 * owner is flagged: a matured company's locked code could favour its owner's
 * other company at its other shareholders' cost, and this shows it.
 */
class Dealings
{
    /** The company's counterparties, the most traded first */
    public static function of(int $company, int $limit = 20): array
    {
        $owner = DB::table('companies')->where('company_id', $company)->value('owner_id');
        // The bank's own companies (#642) share the game's owner: not a player's dealings
        if ($owner !== null && DB::table('users')->where('user_id', $owner)->whereNull('login')->exists()) {
            $owner = null;
        }
        $rows = [];
        $add = function ($other, $name, $otherOwner, string $kind, int $n, int $units, int $credits) use (&$rows, $owner) {
            $r = $rows[$other] ?? ['company_id' => (int) $other, 'name' => $name, 'trades' => 0, 'units' => 0,
                'credits' => 0, 'plunders' => 0,
                'same_owner' => $owner !== null && $otherOwner !== null && (int) $owner === (int) $otherOwner];
            if ($kind === 'trade') {
                $r['trades'] += $n;
                $r['units'] += $units;
                $r['credits'] += $credits;
            } else {
                $r['plunders'] += $n;
            }
            $rows[$other] = $r;
        };
        // Trades, either way round
        foreach ([['seller', 'buyer'], ['buyer', 'seller']] as [$mine, $theirs]) {
            $q = DB::table('trades as t')->join("agents as m", 'm.agent_id', '=', "t.$mine")
                ->join("agents as o", 'o.agent_id', '=', "t.$theirs")->join('companies as c', 'c.company_id', '=', 'o.company_id')
                ->where('m.company_id', $company)->where('o.company_id', '<>', $company)->where('c.house', 0)
                ->groupBy('c.company_id', 'c.name', 'c.owner_id')
                ->get(['c.company_id', 'c.name', 'c.owner_id', DB::raw('COUNT(*) AS n'), DB::raw('SUM(t.units) AS units'),
                    DB::raw('SUM(t.credits) AS credits')]);
            foreach ($q as $r) {
                $add($r->company_id, $r->name, $r->owner_id, 'trade', (int) $r->n, (int) $r->units, (int) $r->credits);
            }
        }
        // Plunders, either way round
        foreach ([['w', 'p'], ['p', 'w']] as [$mine, $theirs]) {
            $q = DB::table('agents as w')->join('agents as p', 'p.agent_id', '=', 'w.plundered_by')
                ->join('companies as c', 'c.company_id', '=', "$theirs.company_id")
                ->where("$mine.company_id", $company)->where("$theirs.company_id", '<>', $company)->where('c.house', 0)
                ->groupBy('c.company_id', 'c.name', 'c.owner_id')
                ->get(['c.company_id', 'c.name', 'c.owner_id', DB::raw('COUNT(*) AS n')]);
            foreach ($q as $r) {
                $add($r->company_id, $r->name, $r->owner_id, 'plunder', (int) $r->n, 0, 0);
            }
        }
        usort($rows, fn ($a, $b) => [$b['same_owner'], $b['credits'], $b['plunders']] <=> [$a['same_owner'], $a['credits'], $a['plunders']]);
        return array_slice(array_values($rows), 0, $limit);
    }
}
