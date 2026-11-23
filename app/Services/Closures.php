<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The kill switch (#925). A company ends only through its own code, with
 * close_company() (or by going broke, by winding up, or with no drones
 * left). The game engine records the call in company_closures, and the
 * website's tick ends the company:
 *
 * - a startup: its debt is cancelled, its credits go back to the bank and
 *   its drones become empty wrecks (Startups::close, as before);
 * - a matured company: its orders are withdrawn, its credits go to its
 *   shareholders by their shares (the bank's part leaves the game), and its
 *   drones become wrecks with whatever they still hold, for plunderers.
 *   (Lenders, paid first, come with loans: #928.)
 *
 * The Close button on the page of a company owned outright pushes SCRIPT,
 * which calls close_company(), so the rule stays the same for everyone.
 */
class Closures
{
    /** What the Close button pushes */
    public const SCRIPT = "-- Closing: the Close button pushed this (#925)\nfunction launched()\n  close_company()\nend\n";

    /** The Close button: pushes SCRIPT to a company its player owns outright */
    public static function button(User $owner, int $company): array
    {
        $r = Startups::reprogram($owner, $company, self::SCRIPT);
        return $r['ok'] ? $r + ['closing' => true] : $r;
    }

    /** The tick: ends each company whose code called close_company(); how many it ended */
    public static function settle(): int
    {
        if (!Schema::hasTable('company_closures')) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('company_closures')->whereNull('done_t')->orderBy('id')->limit(50)->get() as $req) {
            $outcome = self::end((int) $req->company_id);
            DB::table('company_closures')->where('company_id', $req->company_id)->whereNull('done_t')
                ->update(['done_t' => World::gameNow(), 'outcome' => $outcome]);
            $n += $outcome === 'closed' ? 1 : 0;
        }
        return $n;
    }

    /** Ends one company now as its code asked: 'closed', or why not */
    public static function end(int $company): string
    {
        $c = DB::table('companies')->where('company_id', $company)->first();
        if (!$c || $c->eliminated_at !== null) {
            return 'ended_already';
        }
        if ((int) ($c->house ?? 0) === 1) {
            return 'house';
        }
        if ((int) $c->startup === 1) {
            Startups::endStartup($company);
            return 'closed';
        }
        return WindUp::windUp($company, 'closed', true) === null ? 'ended_already' : 'closed';
    }
}
