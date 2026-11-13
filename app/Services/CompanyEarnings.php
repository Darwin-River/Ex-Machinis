<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * A company's earnings by game day (#600), for its page's chart and the API:
 * what its sales brought in after sales tax, what it paid for goods and
 * equipment, and the difference. Processor tax and code push fees are left
 * out: they aren't trades
 */
class CompanyEarnings
{
    /** The longest span asked for, in game days */
    public const MAX_DAYS = 365;

    /**
     * The last $days game days (not before it was founded), oldest first,
     * every day listed: day, sales
     * (after tax), tax, purchases, net (sales - purchases), trades, dividends
     * (paid to other holders from that day's profit, #603)
     */
    public static function daily(int $company, int $days): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $epoch = (int) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $today = intdiv(max(0, World::gameNow() - $epoch), 86400);
        // Not before the company was founded: a young company's chart is its life
        $founded = DB::table('companies')->where('company_id', $company)->value('founded_t');
        $first = max(0, $today - $days + 1, $founded ? intdiv(max(0, (int) $founded - $epoch), 86400) : 0);
        $since = $epoch + $first * 86400;
        $day = "FLOOR((t.game_t - $epoch) / 86400)";

        $rows = [];
        for ($d = $first; $d <= $today; $d++) {
            $rows[$d] = ['day' => $d, 'sales' => 0, 'tax' => 0, 'purchases' => 0, 'net' => 0, 'trades' => 0, 'dividends' => 0];
        }
        // The drones a company has had: its sales and purchases are theirs
        $drones = DB::table('agents')->where('company_id', $company)->pluck('agent_id')->all();
        if ($drones) {
            foreach (DB::table('trades AS t')->whereIn('t.seller', $drones)->where('t.game_t', '>=', $since)
                         ->groupBy(DB::raw($day))
                         ->get([DB::raw("$day AS day"), DB::raw('SUM(t.credits) AS credits'), DB::raw('SUM(t.tax) AS tax'),
                             DB::raw('COUNT(*) AS n')]) as $r) {
                if (isset($rows[(int) $r->day])) {
                    $rows[(int) $r->day]['sales'] += (int) $r->credits - (int) $r->tax;
                    $rows[(int) $r->day]['tax'] += (int) $r->tax;
                    $rows[(int) $r->day]['trades'] += (int) $r->n;
                }
            }
            foreach (DB::table('trades AS t')->whereIn('t.buyer', $drones)->where('t.game_t', '>=', $since)
                         ->groupBy(DB::raw($day))
                         ->get([DB::raw("$day AS day"), DB::raw('SUM(t.credits) AS credits'), DB::raw('COUNT(*) AS n')]) as $r) {
                if (isset($rows[(int) $r->day])) {
                    $rows[(int) $r->day]['purchases'] += (int) $r->credits;
                    $rows[(int) $r->day]['trades'] += (int) $r->n;
                }
            }
        }
        foreach ($rows as &$r) {
            $r['net'] = $r['sales'] - $r['purchases'];
        }
        unset($r);
        // The dividends it paid other holders from each day's profit (#603)
        foreach (DB::table('company_profits')->where('company', $company)->where('day', '>=', $first)
                     ->get(['day', 'paid']) as $p) {
            if (isset($rows[(int) $p->day])) {
                $rows[(int) $p->day]['dividends'] = (int) $p->paid;
            }
        }
        return array_values($rows);
    }
}
