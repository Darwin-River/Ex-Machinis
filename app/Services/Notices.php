<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Opt-in e-mail notices (#761). Round 72's players said which e-mails they
 * would turn on; this is the first four, the ones a class needs, sent through
 * the mail settings the account verification uses (#636):
 *
 * - can_mature: a seedling of theirs can mature (once a seedling)
 * - at_risk: a company of theirs is at risk, stalled, failing or counting
 *   down to dissolving (once a real day a company)
 * - wrecked: a drone of theirs was wrecked
 * - digest: once a real week, their companies, shares and dividends
 *
 * Everything is off until the player turns it on. The first three go
 * together in one e-mail, at most one a real day; each item says what to try
 * next; every e-mail has a link that turns them all off. Pupils have no
 * e-mail address: a teacher may turn on a daily class summary for each class
 * group, the same items for every pupil's companies in one e-mail.
 *
 * Run by the scheduler's keeper; it does its work at most every RUN_EVERY
 * real seconds, and sends at most MAX_MAILS e-mails a run.
 */
class Notices
{
    /** The notices a player can turn on, with what the account page calls them */
    public const KINDS = [
        'can_mature' => 'A seedling of mine can mature',
        'at_risk' => 'A company of mine is at risk, stalled or counting down to dissolving',
        'wrecked' => 'A drone of mine is wrecked',
        'digest' => 'A weekly digest of my companies and shares',
    ];

    /** The three that go in the daily e-mail */
    public const DAILY = ['can_mature', 'at_risk', 'wrecked'];

    /** Real seconds between runs, between daily e-mails, and between digests */
    public const RUN_EVERY = 300;
    public const DAY = 86400;
    public const WEEK = 7 * 86400;

    /** Real seconds before a player or class with nothing to say is looked at again */
    public const QUIET = 3600;

    /** E-mails a run sends at most (the rest wait for the next) */
    public const MAX_MAILS = 40;

    /** What to try next, by kind: one line each (Juno, round 72) */
    public const TRY = [
        'can_mature' => 'Mature it on its page before it reaches its credit cap: past the cap, what it earns goes to the bank.',
        'at_risk' => 'Open its page and read its drones\' status lines and failed actions: a drone short of fuel or stuck on one failing action is the usual cause. Push a fix, or sell fuel to it.',
        'wrecked' => 'Read the drone\'s last moves on its page: a wreck is usually an empty tank or a crash at the end of a flight. Change the script so it checks its fuel before it flies.',
    ];

    public static function available(): bool
    {
        return config('forge.new_rules') && Schema::hasTable('notice_settings');
    }

    /** A player's settings: each kind on or off */
    public static function settings(User $user): array
    {
        $row = DB::table('notice_settings')->where('user_id', $user->user_id)->first();
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $out[$k] = $row ? (bool) $row->$k : false;
        }
        return $out;
    }

    /**
     * Saves which notices a player wants. A digest turned on starts its week
     * now, and wrecks are told from now on, not the ones before
     */
    public static function save(User $user, array $on): void
    {
        $was = DB::table('notice_settings')->where('user_id', $user->user_id)->first();
        $row = ['updated_at' => now()];
        foreach (array_keys(self::KINDS) as $k) {
            $row[$k] = in_array($k, $on, true) ? 1 : 0;
        }
        if ($row['digest'] && (!$was || !$was->digest)) {
            $row['last_digest_at'] = now();
        }
        if ($row['wrecked'] && (!$was || !$was->wrecked)) {
            $row['wrecks_since'] = World::gameNow();
        }
        DB::table('notice_settings')->updateOrInsert(['user_id' => $user->user_id], $row);
    }

    /** Turns every notice off (the e-mail's link) */
    public static function unsubscribe(int $userId): void
    {
        DB::table('notice_settings')->where('user_id', $userId)
            ->update(['can_mature' => 0, 'at_risk' => 0, 'wrecked' => 0, 'digest' => 0, 'updated_at' => now()]);
        DB::table('class_groups')->where('teacher_id', $userId)->update(['notices' => 0]);
    }

    /** A teacher's class summary on or off; false when the group isn't theirs */
    public static function setClass(User $teacher, int $group, bool $on): bool
    {
        $row = DB::table('class_groups')->where('id', $group)->where('teacher_id', $teacher->user_id)->first();
        if (!$row) {
            return false;
        }
        DB::table('class_groups')->where('id', $group)->update(['notices' => $on ? 1 : 0]
            + ($on && !$row->notices ? ['wrecks_since' => World::gameNow()] : []));
        return true;
    }

    /** The link in every e-mail that turns them all off (signed, it needs no login) */
    public static function unsubscribeUrl(int $userId): string
    {
        return url(URL::signedRoute('notices.unsubscribe', ['id' => $userId], null, false));
    }

    /** Sends what is due, at most every RUN_EVERY real seconds: {daily, digests, classes} sent */
    public static function runIfDue(bool $force = false): array
    {
        $out = ['daily' => 0, 'digests' => 0, 'classes' => 0];
        if (!self::available() || (!$force && !Cache::add('notices-run', 1, self::RUN_EVERY))) {
            return $out;
        }
        $left = self::MAX_MAILS;
        $dayAgo = now()->subSeconds(self::DAY);
        $weekAgo = now()->subSeconds(self::WEEK);

        // Players' daily notices: any of the three on, not sent in the last real day
        $players = DB::table('notice_settings as n')->join('users as u', 'u.user_id', '=', 'n.user_id')
            ->whereNotNull('u.email')->whereNotNull('u.email_verified_at')->whereNull('u.class_group_id')
            ->where(fn ($q) => $q->where('n.can_mature', 1)->orWhere('n.at_risk', 1)->orWhere('n.wrecked', 1))
            ->where(fn ($q) => $q->whereNull('n.last_daily_at')->orWhere('n.last_daily_at', '<', $dayAgo))
            ->orderBy('n.user_id')->get(['n.*', 'u.email', 'u.login']);
        foreach ($players as $p) {
            if ($left <= 0) {
                break;
            }
            // Nothing to say an hour ago: look again in an hour, not every run (company health isn't free)
            if (!$force && !Cache::add('notices-checked:' . $p->user_id, 1, self::QUIET)) {
                continue;
            }
            $kinds = array_values(array_filter(self::DAILY, fn ($k) => (bool) $p->$k));
            $now = World::gameNow();
            $items = self::items([(int) $p->user_id => null], $kinds, (int) $p->user_id, (int) ($p->wrecks_since ?? $now));
            if (!$items) {
                continue;
            }
            if (self::send($p->email, new \App\Mail\Notice('What needs you in Ex Machinis', (string) $p->login,
                    'Here is what changed in your companies that needs you.', $items, self::unsubscribeUrl((int) $p->user_id)))) {
                self::record((int) $p->user_id, $items);
                DB::table('notice_settings')->where('user_id', $p->user_id)
                    ->update(['last_daily_at' => now()] + (in_array('wrecked', $kinds, true) ? ['wrecks_since' => $now] : []));
                $out['daily']++;
                $left--;
            }
        }

        // Weekly digests
        $digests = DB::table('notice_settings as n')->join('users as u', 'u.user_id', '=', 'n.user_id')
            ->whereNotNull('u.email')->whereNotNull('u.email_verified_at')->whereNull('u.class_group_id')
            ->where('n.digest', 1)->where(fn ($q) => $q->whereNull('n.last_digest_at')->orWhere('n.last_digest_at', '<', $weekAgo))
            ->orderBy('n.user_id')->get(['n.user_id', 'n.last_digest_at', 'u.email', 'u.login']);
        foreach ($digests as $p) {
            if ($left <= 0) {
                break;
            }
            $user = User::where('user_id', $p->user_id)->first();
            $items = $user ? self::digest($user, $p->last_digest_at ? strtotime($p->last_digest_at) : time() - self::WEEK) : [];
            if ($items && self::send($p->email, new \App\Mail\Notice('Your week in Ex Machinis', (string) $p->login,
                    'Your companies and shares this week.', $items, self::unsubscribeUrl((int) $p->user_id)))) {
                $out['digests']++;
                $left--;
            }
            // A week with nothing to say waits for the next
            DB::table('notice_settings')->where('user_id', $p->user_id)->update(['last_digest_at' => now()]);
        }

        // Teachers' class summaries
        $groups = DB::table('class_groups as g')->join('users as t', 't.user_id', '=', 'g.teacher_id')
            ->where('g.notices', 1)->whereNotNull('t.email')->whereNotNull('t.email_verified_at')
            ->where(fn ($q) => $q->whereNull('g.last_summary_at')->orWhere('g.last_summary_at', '<', $dayAgo))
            ->orderBy('g.id')->get(['g.id', 'g.name', 'g.teacher_id', 'g.wrecks_since', 't.email', 't.login']);
        foreach ($groups as $g) {
            if ($left <= 0) {
                break;
            }
            if (!$force && !Cache::add('notices-class-checked:' . $g->id, 1, self::QUIET)) {
                continue;
            }
            $now = World::gameNow();
            $pupils = DB::table('users')->where('class_group_id', $g->id)->pluck('login', 'user_id')->all();
            $items = $pupils ? self::items($pupils, self::DAILY, (int) $g->teacher_id, (int) ($g->wrecks_since ?? $now)) : [];
            if (!$items) {
                continue;
            }
            if (self::send($g->email, new \App\Mail\Notice('Class ' . $g->name . ': what needs your pupils', (string) $g->login,
                    'What changed for the pupils of ' . $g->name . ' that needs them, with what each could try next.',
                    $items, self::unsubscribeUrl((int) $g->teacher_id)))) {
                self::record((int) $g->teacher_id, $items);
                DB::table('class_groups')->where('id', $g->id)->update(['last_summary_at' => now(), 'wrecks_since' => $now]);
                $out['classes']++;
                $left--;
            }
        }
        if (array_sum($out) > 0) {
            Log::info('notices sent: ' . json_encode($out));
        }
        return $out;
    }

    /**
     * What to tell about these owners' companies: [kind, subject, company,
     * name, text, try, link]. $owners: user id => the pupil's name to put
     * first (a class summary), or null. $to: who it goes to, for what was
     * told already
     */
    public static function items(array $owners, array $kinds, int $to, int $wrecksSince): array
    {
        $companies = DB::table('companies')->whereIn('owner_id', array_keys($owners))->whereNull('eliminated_at')
            ->orderBy('company_id')->get();
        $items = [];
        $who = fn ($c) => ($owners[(int) $c->owner_id] ?? null) !== null ? $owners[(int) $c->owner_id] . ': ' : '';
        if (in_array('can_mature', $kinds, true)) {
            foreach ($companies->where('seedling', 1) as $c) {
                if (Seedlings::canMature($c) && !self::told($to, 'can_mature', (int) $c->company_id, null)) {
                    $need = (int) $c->debt + Seedlings::matureMargin();
                    $cap = Seedlings::creditCap($c);
                    $items[] = self::item('can_mature', (int) $c->company_id, $c, $who($c) . $c->name . ' can mature: it holds '
                        . number_format((int) $c->credits - (int) $c->compute_debt) . ' of the ' . number_format($need) . ' credits it needs'
                        . ($cap !== null ? ', and past ' . number_format($cap) . ' its earnings go to the bank' : '') . '.');
                }
            }
        }
        if (in_array('at_risk', $kinds, true)) {
            foreach ($companies as $c) {
                if (self::told($to, 'at_risk', (int) $c->company_id, now()->subSeconds(self::DAY))) {
                    continue;
                }
                $h = CompanyHealth::of((int) $c->company_id);
                $why = $h['at_risk_reason'] ?? $h['failing_reason'] ?? $h['stalled_reason'] ?? null;
                if ($why !== null) {
                    $what = $h['at_risk'] ? 'is at risk' : (!empty($h['failing_reason']) ? 'is failing' : 'has stalled');
                    $items[] = self::item('at_risk', (int) $c->company_id, $c, $who($c) . $c->name . ' ' . $what . ': ' . $why . '.');
                }
            }
        }
        if (in_array('wrecked', $kinds, true) && $companies->isNotEmpty()) {
            $byId = $companies->keyBy('company_id');
            $wrecks = DB::table('agents as a')->leftJoin('objects as o', 'o.object_id', '=', 'a.object_id')
                ->whereIn('a.company_id', $companies->pluck('company_id')->all())->where('a.wrecked_at', '>', $wrecksSince)
                ->orderBy('a.wrecked_at')->limit(20)->get(['a.agent_id', 'a.name', 'a.company_id', 'a.wreck_cause', 'a.wrecked_at', 'o.object_name']);
            foreach ($wrecks as $w) {
                $c = $byId[$w->company_id];
                $items[] = ['kind' => 'wrecked', 'subject' => (int) $w->agent_id, 'company' => (int) $c->company_id,
                    'text' => $who($c) . 'Drone ' . $w->name . ' of ' . $c->name . ' was wrecked'
                        . ($w->wreck_cause ? ' (' . str_replace('_', ' ', $w->wreck_cause) . ')' : '')
                        . ($w->object_name ? ' at ' . $w->object_name : '') . ', ' . GameDate::at((int) $w->wrecked_at) . '.',
                    'try' => self::TRY['wrecked'], 'link' => url('spacecraft/' . $w->agent_id)];
            }
        }
        return $items;
    }

    /**
     * The weekly digest's lines for a player: each live company of theirs
     * (net wealth, a seedling's progress to maturing, its health), their
     * shares' cash-out value, dividends since the last digest and open orders
     */
    public static function digest(User $user, int $since): array
    {
        $items = [];
        $board = Leaderboard::wealth();
        foreach (DB::table('companies')->where('owner_id', $user->user_id)->whereNull('eliminated_at')->orderBy('company_id')->get() as $c) {
            $parts = [];
            if (isset($board[$c->company_id])) {
                $parts[] = 'net wealth ' . number_format($board[$c->company_id]['wealth']) . ' with ' . $board[$c->company_id]['drones']
                    . ' working ' . ($board[$c->company_id]['drones'] === 1 ? 'drone' : 'drones');
            }
            if ((int) $c->seedling === 1) {
                $need = (int) $c->debt + Seedlings::matureMargin();
                $parts[] = Seedlings::canMature($c) ? 'it can mature now'
                    : number_format((int) $c->credits - (int) $c->compute_debt) . ' of the ' . number_format($need) . ' credits it needs to mature';
            }
            $h = CompanyHealth::of((int) $c->company_id);
            $why = $h['at_risk_reason'] ?? $h['stalled_reason'] ?? null;
            $parts[] = $why !== null ? ($h['at_risk'] ? 'at risk: ' : 'stalled: ') . $why : 'steady';
            $items[] = ['kind' => 'digest', 'subject' => (int) $c->company_id, 'company' => (int) $c->company_id,
                'text' => $c->name . ': ' . implode('; ', $parts) . '.', 'try' => null, 'link' => url('companies/' . $c->company_id)];
        }
        $mine = PlayerShares::of($user);
        if ($mine['holdings']) {
            $value = array_sum(array_column($mine['holdings'], 'value'));
            $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
            $fromDay = intdiv(WorldEvents::gameTimeAt($since) - $epoch, 86400);
            $paid = (int) DB::table('player_dividends')->where('user_id', $user->user_id)->where('day', '>=', $fromDay)->sum('amount');
            $items[] = ['kind' => 'digest', 'subject' => 0, 'company' => null,
                'text' => 'Your shares in ' . count($mine['holdings']) . ' ' . (count($mine['holdings']) === 1 ? 'company' : 'companies')
                    . ' are worth ' . number_format($value) . ' credits at cash-out; they paid ' . number_format($paid)
                    . ' credits in dividends this week' . ($mine['orders'] ? ', and you have ' . count($mine['orders']) . ' open '
                    . (count($mine['orders']) === 1 ? 'order' : 'orders') : '') . '.',
                'try' => null, 'link' => url('market/trade')];
        }
        return $items;
    }

    private static function item(string $kind, int $subject, object $c, string $text): array
    {
        return ['kind' => $kind, 'subject' => $subject, 'company' => (int) $c->company_id, 'text' => $text,
            'try' => self::TRY[$kind], 'link' => url('companies/' . $c->company_id)];
    }

    /** Was this told to them already (since a time, or ever)? */
    private static function told(int $to, string $kind, int $subject, $since): bool
    {
        $q = DB::table('notices_sent')->where('user_id', $to)->where('kind', $kind)->where('subject', $subject);
        if ($since !== null) {
            $q->where('sent_at', '>', $since);
        }
        return $q->exists();
    }

    private static function record(int $to, array $items): void
    {
        $rows = array_map(fn ($i) => ['user_id' => $to, 'kind' => $i['kind'], 'subject' => $i['subject'], 'sent_at' => now()], $items);
        DB::table('notices_sent')->insert($rows);
    }

    private static function send(string $to, \Illuminate\Mail\Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Notice mail failed: ' . $e->getMessage());
            return false;
        }
    }
}
