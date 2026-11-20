<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * A failure card (#773): when a company ends, a short post-mortem on its
 * page. Why it ended, how its drones ended and where, what it was failing
 * at the end, its last error with the line of code involved, and a link to
 * start a fixed copy on the Found a startup page with its code loaded.
 * Break, learn, repeat: the next try is a click away.
 */
class PostMortem
{
    /** Why a company ended (companies.end_cause), in plain words */
    public const ENDS = [
        'closed' => 'Its player closed it',
        'dissolved' => 'It had no credits for too long, and dissolved',
        'wound_up' => 'It sold nothing for 60 game days, and was wound up: its credits went to its shareholders',
        'drones' => 'It lost its last drone',
        'tax' => "It couldn't pay its tax",
        'reset' => 'The world was reset',
    ];

    /** The card for an ended company, or null while it runs */
    public static function of(int $companyId): ?array
    {
        $c = DB::table('companies')->where('company_id', $companyId)->first();
        if (!$c || $c->eliminated_at === null) {
            return null;
        }
        $end = (int) $c->eliminated_at;
        $drones = DB::table('agents as a')->leftJoin('objects as o', 'o.object_id', '=', 'a.object_id')
            ->where('a.company_id', $companyId)->orderBy('a.agent_id')
            ->get(['a.agent_id', 'a.name', 'a.wreck_cause', 'a.wrecked_at', 'a.script_status', 'a.script_status_t', 'o.object_name as place']);
        $lines = [];
        $lines[] = (self::ENDS[$c->end_cause ?? ''] ?? 'It ended') . ' on ' . DronePage::when($end) . '.';

        // How its drones ended, and where: those that went before the company first
        $early = $drones->filter(fn ($d) => $d->wrecked_at !== null && (int) $d->wrecked_at < $end - 60
            && !in_array($d->wreck_cause, ['closed', 'dissolved', 'wound_up', 'tax', 'reset'], true));
        foreach ($early->groupBy('wreck_cause') as $cause => $group) {
            $places = $group->pluck('place')->filter()->unique()->values()->all();
            $lines[] = self::count($group->count(), 'drone') . ' ' . lcfirst(DronePage::WRECK_CAUSES[$cause] ?? 'became a wreck')
                . ($places ? ' (at ' . implode(', ', array_slice($places, 0, 3)) . ')' : '') . ' before that.';
        }

        // What it was failing at, over its last game day
        $failing = CompanyHealth::failing($companyId, $end);
        if ($failing) {
            $f = $failing[0];
            $lines[] = 'Over its last game day, ' . $f['what'] . ' failed ' . $f['times'] . ' of ' . $f['tries'] . ' times: '
                . $f['why'] . '.' . (($hint = self::hint($f['why'])) ? ' Usually: ' . $hint . '.' : '');
        }

        // What its drones last said (set_status)
        $said = $drones->filter(fn ($d) => $d->script_status !== null)->sortByDesc('script_status_t')->first();
        if ($said) {
            $lines[] = $said->name . ' last said: "' . $said->script_status . '".';
        }

        // Its last error, and the line of code involved
        $error = self::lastError($drones->pluck('agent_id')->all(), $end);
        $code = CompanyCode::of($companyId);
        $line = null;
        if ($error && $code && preg_match('/^script:(\d+):/', $error['text'], $m)) {
            $n = (int) $m[1];
            $source = explode("\n", (string) $code['current']['script']);
            if ($n >= 1 && $n <= count($source)) {
                $line = ['number' => $n, 'code' => rtrim($source[$n - 1]), 'in' => $code['current']['label']];
            }
        }

        // Start a fixed copy: the Found a startup page with its newest code loaded
        $copy = null;
        if ($code) {
            $copy = 'release?copy=' . (int) $code['release']->agent_id
                . ($code['current']['number'] > 0 ? '&push=' . $code['current']['number'] : '') . '&fix=' . $companyId;
        }
        return ['company_id' => $companyId, 'name' => $c->name, 'end_cause' => $c->end_cause, 'when' => DronePage::when($end),
            'game_time' => $end, 'lines' => $lines, 'error' => $error, 'line' => $line, 'copy' => $copy];
    }

    /** A drone's last error message before the end, {drone, when, text}, or null */
    private static function lastError(array $drones, int $end): ?array
    {
        if (!$drones) {
            return null;
        }
        $m = DB::table('drone_messages as m')->join('agents as a', 'a.agent_id', '=', 'm.agent_id')
            ->whereIn('m.agent_id', $drones)->where('m.game_t', '<=', $end + 86400)->where('m.body', 'like', '%error: %')
            ->orderByDesc('m.id')->first(['a.name', 'm.game_t', 'm.body']);
        if (!$m || !preg_match('/error: (.+)/', (string) $m->body, $e)) {
            return null;
        }
        return ['drone' => $m->name, 'when' => DronePage::when((int) $m->game_t), 'text' => mb_substr(trim($e[1]), 0, 300)];
    }

    private static function hint(string $why): ?string
    {
        foreach (SinceLooked::HINTS as $code => $hint) {
            if ((DronePage::OUTCOMES[$code] ?? null) === $why) {
                return $hint;
            }
        }
        return null;
    }

    private static function count(int $n, string $what): string
    {
        return ($n === 1 ? 'One ' : $n . ' ') . $what . ($n === 1 ? '' : 's');
    }
}
