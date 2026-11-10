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
            $number = (int) $company->pushes + 1;
            DB::table('code_pushes')->insert(['company_id' => $companyId, 'number' => $number,
                'game_t' => World::gameNow(), 'credits_before' => (int) $company->credits, 'permille' => $permille,
                'fee' => $fee, 'drones' => count($drones), 'script' => $script]);
            $pieces = Release::chunks($script);
            foreach ($drones as $drone) {
                Command::create(['code' => '<reset>', 'agent_id' => $drone, 'subject' => 'Push', 'email_content' => '']);
                foreach ($pieces as $piece) {
                    Command::create(['code' => $piece, 'agent_id' => $drone, 'subject' => 'Push', 'email_content' => '']);
                }
                Command::create(['code' => '<launched>', 'agent_id' => $drone, 'subject' => 'Push', 'email_content' => '']);
            }
            return ['ok' => true, 'number' => $number, 'fee' => $fee, 'permille' => $permille, 'drones' => $drones,
                'next_permille' => self::permille($number)];
        });
    }

    /** A company's pushes, newest first: number, when, fee, permille, drones */
    public static function log(int $companyId): array
    {
        return DB::table('code_pushes')->where('company_id', $companyId)->orderByDesc('id')
            ->get(['number', 'game_t', 'fee', 'permille', 'drones'])
            ->map(fn ($p) => ['number' => (int) $p->number, 'when' => DronePage::when((int) $p->game_t),
                'game_time' => (int) $p->game_t, 'fee' => (int) $p->fee, 'percent' => $p->permille / 10,
                'drones' => (int) $p->drones])->all();
    }

    /** What the company page and API say about pushes */
    public static function summary(object $company): array
    {
        [$permille, $fee] = self::nextFee($company);
        return ['enabled' => $company->push_password !== null, 'count' => (int) $company->pushes,
            'next_fee_percent' => $permille / 10, 'next_fee' => $fee, 'log' => self::log((int) $company->company_id)];
    }
}
