<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code lineage (#616, prototype #613): each company's code revisions (its
 * release, then each push) get parents, the earlier revisions whose code
 * they carry, with the share each explains. Technology recombines, so a
 * revision can have several parents, and edges only run from older
 * revisions to newer ones. What no earlier revision explains is new code.
 *
 * Genes: a script's top-level functions, and (main) for the rest. Each is
 * tokenized and normalized: names the script binds become V1, V2… in order
 * of first use, so renaming changes nothing; the game library's and Lua's
 * names, and field names, keep their names; numbers and strings become N
 * and S. (main)'s lines are compared in any order.
 *
 * Parents are chosen greedily from strictly earlier revisions: the one that
 * explains the most of the code not yet explained, then the next, while
 * each adds MIN_SHARE. An identical gene explains a gene fully; a close one
 * explains 1 - edit distance / length of it when that is at least MIN_SIM.
 * Ties go to the earliest revision. The starter and example scripts
 * (examples/) are undated roots.
 */
class CodeLineage
{
    public const MIN_SHARE = 0.05;
    public const MIN_SIM = 0.5;
    private const KEYWORDS = ['and', 'break', 'do', 'else', 'elseif', 'end', 'false', 'for', 'function', 'goto', 'if',
        'in', 'local', 'nil', 'not', 'or', 'repeat', 'return', 'then', 'true', 'until', 'while'];
    private const KEPT = ['launched', 'self', '_G', '_ENV'];
    private static ?array $library = null;

    // ---- Tokens and genes ------------------------------------------------

    /** A Lua script's tokens: [type (name, kw, num, str, op), text, line]; comments and spaces dropped */
    public static function tokens(string $src): array
    {
        $out = [];
        $n = strlen($src);
        $i = 0;
        $line = 1;
        $longClose = function (int $at) use ($src): ?array {
            // [[ or [=*[ at $at: returns [closing, opening length]
            if (preg_match('/\G\[(=*)\[/', $src, $m, 0, $at)) {
                return [']' . $m[1] . ']', strlen($m[0])];
            }
            return null;
        };
        while ($i < $n) {
            $c = $src[$i];
            if ($c === "\n") {
                $line++;
                $i++;
                continue;
            }
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '-' && substr($src, $i, 2) === '--') {
                $long = $longClose($i + 2);
                if ($long) {
                    $end = strpos($src, $long[0], $i + 2 + $long[1]);
                    $end = $end === false ? $n : $end + strlen($long[0]);
                } else {
                    $end = strpos($src, "\n", $i);
                    $end = $end === false ? $n : $end;
                }
                $line += substr_count($src, "\n", $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === '[' && ($long = $longClose($i))) {
                $end = strpos($src, $long[0], $i + $long[1]);
                $end = $end === false ? $n : $end + strlen($long[0]);
                $out[] = ['str', substr($src, $i, $end - $i), $line];
                $line += substr_count($src, "\n", $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $src[$j] !== $c && $src[$j] !== "\n") {
                    $j += $src[$j] === '\\' ? 2 : 1;
                }
                $out[] = ['str', substr($src, $i, $j + 1 - $i), $line];
                $i = $j + 1;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($src[$i + 1]))) {
                preg_match('/\G(0[xX][0-9a-fA-F.]*([pP][+-]?\d+)?|[0-9.]+([eE][+-]?\d+)?)/', $src, $m, 0, $i);
                $out[] = ['num', $m[0], $line];
                $i += strlen($m[0]);
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $src, $m, 0, $i);
                $out[] = [in_array($m[0], self::KEYWORDS, true) ? 'kw' : 'name', $m[0], $line];
                $i += strlen($m[0]);
                continue;
            }
            foreach (['...', '..', '==', '~=', '<=', '>=', '//', '::', '<<', '>>'] as $op) {
                if (substr($src, $i, strlen($op)) === $op) {
                    $out[] = ['op', $op, $line];
                    $i += strlen($op);
                    continue 2;
                }
            }
            $out[] = ['op', $c, $line];
            $i++;
        }
        return $out;
    }

    /** The game library's names (lua_library.lua): a script that redefines one keeps its name */
    private static function library(): array
    {
        if (self::$library === null) {
            $src = @file_get_contents(base_path('game-engine/src/lua_library.lua')) ?: '';
            preg_match_all('/^\s*function\s+([A-Za-z_]\w*)|^\s*_G\.(\w+)|^([A-Za-z_]\w*)\s*=/m', $src, $m);
            self::$library = array_fill_keys(array_filter(array_merge($m[1], $m[2], $m[3])), true);
        }
        return self::$library;
    }

    /** Names the script binds: locals, functions, parameters, loop variables and global assignments */
    private static function boundNames(array $t): array
    {
        $bound = [];
        $depth = 0;   // ( { [ nesting
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            [$type, $text] = $t[$i];
            if ($type === 'op' && in_array($text, ['(', '{', '['], true)) {
                $depth++;
            } elseif ($type === 'op' && in_array($text, [')', '}', ']'], true)) {
                $depth = max(0, $depth - 1);
            }
            if ($type === 'kw' && ($text === 'local' || $text === 'for')) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($t[$j][0] === 'name') {
                        $bound[$t[$j][1]] = true;
                    } elseif (!($t[$j][0] === 'op' && in_array($t[$j][1], [',', '<', '>'], true))
                              && !($t[$j][0] === 'kw' && $t[$j][1] === 'function')) {
                        break;
                    }
                }
            }
            if ($type === 'kw' && $text === 'function') {
                $j = $i + 1;
                if ($j < $n && $t[$j][0] === 'name' && !($j + 1 < $n && in_array($t[$j + 1][1], ['.', ':'], true))) {
                    $bound[$t[$j][1]] = true;
                }
                while ($j < $n && $t[$j][1] !== '(') {
                    $j++;
                }
                for ($j++; $j < $n && $t[$j][1] !== ')'; $j++) {
                    if ($t[$j][0] === 'name') {
                        $bound[$t[$j][1]] = true;
                    }
                }
            }
            // a, b = … at the statement level (not a table key, not a field)
            if ($type === 'name' && $depth === 0 && !($i > 0 && in_array($t[$i - 1][1], ['.', ':'], true))) {
                $j = $i + 1;
                while ($j + 1 < $n && $t[$j][1] === ',' && $t[$j + 1][0] === 'name') {
                    $j += 2;
                }
                if ($j < $n && $t[$j][0] === 'op' && $t[$j][1] === '=') {
                    for ($k = $i; $k < $j; $k += 2) {
                        $bound[$t[$k][1]] = true;
                    }
                }
            }
        }
        foreach (array_merge(array_keys(self::library()), self::KEPT) as $name) {
            unset($bound[$name]);
        }
        return $bound;
    }

    /** Tokens as normalized text; $canon numbers bound names in order of first use */
    private static function normalize(array $t, array $bound, array &$canon): array
    {
        $out = [];
        $braces = [];   // a stack: whether each open bracket is a table constructor
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            [$type, $text] = $t[$i];
            if ($type === 'op' && in_array($text, ['(', '{', '['], true)) {
                $braces[] = $text === '{';
            } elseif ($type === 'op' && in_array($text, [')', '}', ']'], true)) {
                array_pop($braces);
            }
            if ($type === 'num') {
                $out[] = 'N';
            } elseif ($type === 'str') {
                $out[] = 'S';
            } elseif ($type !== 'name') {
                $out[] = $text;
            } elseif ($i > 0 && in_array($t[$i - 1][1], ['.', ':'], true)) {
                $out[] = 'K:' . $text;                         // a field
            } elseif (end($braces) === true && $i + 1 < $n && $t[$i + 1][1] === '=') {
                $out[] = 'K:' . $text;                         // a table key
            } elseif (isset($bound[$text])) {
                $canon[$text] ??= 'V' . (count($canon) + 1);
                $out[] = $canon[$text];
            } else {
                $out[] = 'E:' . $text;                         // the library's, Lua's, the game's
            }
        }
        return $out;
    }

    /**
     * A script's genes: [name, hash, size, tokens] for each top-level
     * function, and (main) for the rest (its lines sorted, each numbered on
     * its own, so their order doesn't matter)
     */
    public static function genes(string $src): array
    {
        $t = self::tokens($src);
        $bound = self::boundNames($t);
        $genes = [];
        $main = [];
        $n = count($t);
        for ($i = 0; $i < $n;) {
            // function a.b:c(…) … end | local function f(…) … end | a.b = function(…) … end
            $start = null;
            $name = null;
            if ($t[$i][0] === 'kw' && $t[$i][1] === 'function' && $i + 1 < $n && $t[$i + 1][0] === 'name') {
                $start = $i;
                [$name, $fn] = self::chain($t, $i + 1);
            } elseif ($t[$i][1] === 'local' && $i + 2 < $n && $t[$i + 1][1] === 'function' && $t[$i + 2][0] === 'name') {
                $start = $i + 1;
                $name = $t[$i + 2][1];
            } elseif ($t[$i][0] === 'name') {
                [$chainName, $j] = self::chain($t, $i);
                if ($j + 1 < $n && $t[$j][1] === '=' && $t[$j + 1][1] === 'function') {
                    $start = $j + 1;
                    $name = $chainName;
                }
            }
            if ($start !== null) {
                $end = self::blockEnd($t, $start);
                $canon = [];
                // The function's own name isn't part of its gene: from its parameters on
                $from = $start;
                while ($from < $end && $t[$from][1] !== '(') {
                    $from++;
                }
                $genes[] = self::gene($name, self::normalize(array_slice($t, $from, $end - $from + 1), $bound, $canon));
                $i = $end + 1;
                continue;
            }
            $main[$t[$i][2]][] = $t[$i];
            $i++;
        }
        $lines = [];
        foreach ($main as $tokens) {
            $canon = [];
            $lines[] = implode(' ', self::normalize($tokens, $bound, $canon));
        }
        sort($lines);
        $genes[] = self::gene('(main)', $lines ? explode(' ', implode(' ; ', $lines)) : []);
        return $genes;
    }

    private static function gene(string $name, array $tokens): array
    {
        return ['name' => $name, 'hash' => sha1(implode(' ', $tokens)), 'size' => max(1, count($tokens)), 'tokens' => $tokens];
    }

    /** a.b:c from $i: [the name, the index after it] */
    private static function chain(array $t, int $i): array
    {
        $name = $t[$i][1];
        $j = $i + 1;
        while ($j + 1 < count($t) && in_array($t[$j][1], ['.', ':'], true) && $t[$j + 1][0] === 'name') {
            $name .= $t[$j][1] . $t[$j + 1][1];
            $j += 2;
        }
        return [$name, $j];
    }

    /** The index of the end that closes the block opened at $i (function, if, do, repeat … until) */
    private static function blockEnd(array $t, int $i): int
    {
        $depth = 0;
        for ($n = count($t); $i < $n; $i++) {
            if ($t[$i][0] !== 'kw') {
                continue;
            }
            if (in_array($t[$i][1], ['function', 'if', 'do', 'repeat'], true)) {
                $depth++;
            } elseif (in_array($t[$i][1], ['end', 'until'], true) && --$depth === 0) {
                return $i;
            }
        }
        return count($t) - 1;
    }

    // ---- Comparing genes -------------------------------------------------

    private static array $sim = [];

    /** How much of gene $g gene $h explains: 1 identical, 1 - distance / length when at least MIN_SIM, else 0 */
    public static function similarity(array $g, array $h): float
    {
        if ($g['hash'] === $h['hash']) {
            return 1.0;
        }
        $key = $g['hash'] . $h['hash'];
        if (isset(self::$sim[$key])) {
            return self::$sim[$key];
        }
        $long = max($g['size'], $h['size']);
        $s = 0.0;
        if ($h['size'] >= $g['size'] / 2 && $h['size'] <= $g['size'] * 2) {
            // Each edit changes the token counts by at most 2: half their difference bounds the distance
            $cg = array_count_values($g['tokens']);
            $ch = array_count_values($h['tokens']);
            $l1 = 0;
            foreach ($cg + $ch as $k => $_) {
                $l1 += abs(($cg[$k] ?? 0) - ($ch[$k] ?? 0));
            }
            if ($l1 / 2 <= (1 - self::MIN_SIM) * $long) {
                $s = 1 - self::distance($g['tokens'], $h['tokens']) / $long;
                $s = $s >= self::MIN_SIM ? $s : 0.0;
            }
        }
        return self::$sim[$key] = $s;
    }

    /** Edit distance between token lists: PHP's levenshtein on one character a token, when they fit */
    private static function distance(array $a, array $b): int
    {
        $alphabet = array_flip(array_values(array_unique(array_merge($a, $b))));
        if (count($alphabet) <= 255) {
            $sa = implode('', array_map(fn ($x) => chr($alphabet[$x] + 1), $a));
            $sb = implode('', array_map(fn ($x) => chr($alphabet[$x] + 1), $b));
            return levenshtein($sa, $sb);
        }
        $prev = range(0, count($b));
        foreach ($a as $i => $x) {
            $cur = [$i + 1];
            foreach ($b as $j => $y) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($x === $y ? 0 : 1));
            }
            $prev = $cur;
        }
        return end($prev);
    }

    /**
     * Parents for a revision's genes among earlier revisions: [[key, share],
     * …] in the order chosen, and the share of new code. $earlier: key =>
     * genes, oldest first (ties go to the first).
     */
    public static function parents(array $genes, array $earlier): array
    {
        $mass = array_sum(array_column($genes, 'size'));
        $cover = [];
        foreach ($earlier as $key => $theirs) {
            $byHash = array_column($theirs, null, 'hash');
            $row = [];
            foreach ($genes as $g) {
                if (isset($byHash[$g['hash']])) {
                    $row[] = 1.0;
                    continue;
                }
                $best = 0.0;
                foreach ($theirs as $h) {
                    if (($h['name'] === '(main)') !== ($g['name'] === '(main)')) {
                        continue;
                    }
                    $best = max($best, self::similarity($g, $h));
                }
                $row[] = $best;
            }
            $cover[$key] = $row;
        }
        $got = array_fill(0, count($genes), 0.0);
        $chosen = [];
        while (true) {
            $best = null;
            $gain = 0.0;
            foreach ($cover as $key => $row) {
                $add = 0.0;
                foreach ($genes as $i => $g) {
                    $add += $g['size'] * max(0.0, $row[$i] - $got[$i]);
                }
                $add /= $mass;
                if ($add > $gain + 1e-9) {
                    [$best, $gain] = [$key, $add];
                }
            }
            if ($best === null || $gain < self::MIN_SHARE) {
                break;
            }
            $chosen[] = [$best, round($gain, 4)];
            foreach ($got as $i => $e) {
                $got[$i] = max($e, $cover[$best][$i]);
            }
            unset($cover[$best]);
        }
        $explained = 0.0;
        foreach ($genes as $i => $g) {
            $explained += $g['size'] * $got[$i];
        }
        return [$chosen, round(1 - $explained / $mass, 4)];
    }

    // ---- Stored lineage ----------------------------------------------------

    private static int $examplesChecked = 0;

    /**
     * Works out new revisions' genes and parents, a few at a time: the
     * website's tick calls it (HouseMarket::tick), and forge:lineage runs it
     * to the end. Revisions get genes first; parents only once every
     * release and push has its genes, oldest first. Returns the revisions
     * it worked on.
     */
    public static function catchUp(int $limit = 40): int
    {
        if (!Schema::hasTable('code_revisions')) {
            return 0;
        }
        $done = 0;
        if (self::$examplesChecked < time() - 3600 || !DB::table('code_revisions')->whereNotNull('example')->exists()) {
            $done += self::syncExamples();
            self::$examplesChecked = time();
        }
        $releases = DB::select('SELECT r.company_id, r.source, c.founded_t FROM releases AS r
                JOIN companies AS c ON c.company_id = r.company_id
                LEFT JOIN code_revisions AS cr ON cr.company_id = r.company_id AND cr.push_number = 0
                WHERE cr.id IS NULL AND r.id = (SELECT MIN(r2.id) FROM releases AS r2 WHERE r2.company_id = r.company_id)
                ORDER BY r.id LIMIT ' . $limit);
        foreach ($releases as $r) {
            self::store((int) $r->company_id, 0, null, $r->founded_t === null ? World::gameNow() : (int) $r->founded_t, $r->source);
            $done++;
        }
        $pushes = DB::select('SELECT p.company_id, p.number, p.game_t, p.script FROM code_pushes AS p
                LEFT JOIN code_revisions AS cr ON cr.company_id = p.company_id AND cr.push_number = p.number
                WHERE cr.id IS NULL ORDER BY p.id LIMIT ' . $limit);
        foreach ($pushes as $p) {
            self::store((int) $p->company_id, (int) $p->number, null, (int) $p->game_t, $p->script);
            $done++;
        }
        if (count($releases) === $limit || count($pushes) === $limit) {
            return $done;   // parents wait until every earlier revision has its genes
        }
        $todo = DB::table('code_revisions')->whereNull('new_share')
            ->orderByRaw('game_t IS NOT NULL, game_t, id')->limit($limit)->get(['id', 'game_t']);
        if ($todo->isEmpty()) {
            return $done;
        }
        $revs = DB::table('code_revisions')->orderByRaw('game_t IS NOT NULL, game_t, id')->get(['id', 'game_t']);
        $genes = [];
        foreach (DB::table('code_genes')->orderBy('revision_id')->orderBy('seq')->get() as $g) {
            $genes[$g->revision_id][] = ['name' => $g->name, 'hash' => $g->hash, 'size' => (int) $g->size,
                'tokens' => $g->tokens === '' ? [] : explode(' ', $g->tokens)];
        }
        foreach ($todo as $x) {
            $earlier = [];
            if ($x->game_t !== null) {
                foreach ($revs as $r) {
                    if ($r->game_t === null || (int) $r->game_t < (int) $x->game_t) {
                        $earlier[$r->id] = $genes[$r->id] ?? [];
                    }
                }
            }
            [$parents, $new] = $earlier && isset($genes[$x->id]) ? self::parents($genes[$x->id], $earlier) : [[], 1.0];
            DB::transaction(function () use ($x, $parents, $new) {
                DB::table('code_parents')->where('revision_id', $x->id)->delete();
                foreach ($parents as $i => [$parent, $share]) {
                    DB::table('code_parents')->insert(['revision_id' => $x->id, 'parent_id' => $parent,
                        'rank_no' => $i + 1, 'share' => $share]);
                }
                DB::table('code_revisions')->where('id', $x->id)->update(['new_share' => $new]);
            });
            $done++;
        }
        return $done;
    }

    /** The starter and example scripts as undated roots; one whose file changed gets its new genes */
    private static function syncExamples(): int
    {
        $done = 0;
        foreach (glob(base_path('examples/{starters,companies}/*.lua'), GLOB_BRACE) ?: [] as $file) {
            $key = basename(dirname($file)) . '/' . basename($file, '.lua');
            $src = (string) file_get_contents($file);
            $row = DB::table('code_revisions')->where('example', $key)->first();
            if ($row && $row->source_hash === sha1($src)) {
                continue;
            }
            self::store(null, 0, $key, null, $src, $row ? (int) $row->id : null);
            $done++;
        }
        return $done;
    }

    /** Saves a revision and its genes (parents come later); returns its id */
    private static function store(?int $companyId, int $number, ?string $example, ?int $gameT, string $src, ?int $id = null): int
    {
        $genes = self::genes($src);
        return DB::transaction(function () use ($companyId, $number, $example, $gameT, $src, $id, $genes) {
            $row = ['company_id' => $companyId, 'push_number' => $number, 'example' => $example, 'game_t' => $gameT,
                'source_hash' => sha1($src), 'size' => array_sum(array_column($genes, 'size')),
                'new_share' => $example !== null ? 1.0 : null];
            if ($id === null) {
                $id = (int) DB::table('code_revisions')->insertGetId($row);
            } else {
                DB::table('code_revisions')->where('id', $id)->update($row);
                DB::table('code_genes')->where('revision_id', $id)->delete();
            }
            foreach ($genes as $i => $g) {
                DB::table('code_genes')->insert(['revision_id' => $id, 'seq' => $i, 'name' => mb_substr($g['name'], 0, 128),
                    'hash' => $g['hash'], 'size' => $g['size'], 'tokens' => implode(' ', $g['tokens'])]);
            }
            return $id;
        });
    }

    /** How a revision is named on the site: [label, link] */
    private static function describe(object $r, array $names): array
    {
        if ($r->example !== null) {
            [$dir, $name] = explode('/', $r->example, 2);
            return ["the $name " . ($dir === 'starters' ? 'starter' : 'example'),
                "https://github.com/Darwin-River/Ex-Machinis/blob/main/examples/{$r->example}.lua"];
        }
        $company = $names[$r->company_id] ?? ('company ' . $r->company_id);
        return [$company . ((int) $r->push_number ? ' push ' . $r->push_number : ''), url('/companies/' . $r->company_id) . '#code'];
    }

    /**
     * A company's lineage (#616), or null before it is worked out:
     * revisions (oldest first): number, label, new_share (null while it is
     * being worked out) and parents [label, url, company_id, number,
     * example, share]; children: other companies' revisions that carry its
     * code [label, url, company_id, number, share], oldest first
     */
    public static function ofCompany(int $companyId): ?array
    {
        if (!Schema::hasTable('code_revisions')) {
            return null;
        }
        $mine = DB::table('code_revisions')->where('company_id', $companyId)->orderBy('push_number')->get();
        if ($mine->isEmpty()) {
            return null;
        }
        $ids = $mine->pluck('id')->all();
        $links = DB::table('code_parents')->whereIn('revision_id', $ids)->orWhereIn('parent_id', $ids)->get();
        $others = DB::table('code_revisions')->whereIn('id', $links->pluck('parent_id')->merge($links->pluck('revision_id'))->unique()->all())
            ->get()->keyBy('id');
        $names = DB::table('companies')->whereIn('company_id', $others->pluck('company_id')->filter()->push($companyId)->unique()->all())
            ->pluck('name', 'company_id')->all();
        $row = function ($r, $share) use ($names) {
            [$label, $url] = self::describe($r, $names);
            return ['label' => $label, 'url' => $url, 'company_id' => $r->company_id === null ? null : (int) $r->company_id,
                'number' => (int) $r->push_number, 'example' => $r->example, 'share' => round((float) $share, 3)];
        };
        $revisions = [];
        foreach ($mine as $r) {
            $parents = $links->where('revision_id', $r->id)->sortBy('rank_no')
                ->map(fn ($l) => $row($others[$l->parent_id], $l->share))->values()->all();
            $revisions[] = ['number' => (int) $r->push_number, 'label' => (int) $r->push_number ? 'Push ' . $r->push_number : 'Release',
                'new_share' => $r->new_share === null ? null : round((float) $r->new_share, 3), 'parents' => $parents];
        }
        $children = $links->whereIn('parent_id', $ids)
            ->filter(fn ($l) => (int) ($others[$l->revision_id]->company_id ?? 0) !== $companyId)
            ->groupBy('revision_id')
            ->map(fn ($ls, $child) => $row($others[$child], $ls->sum('share')) + ['t' => (int) $others[$child]->game_t])
            ->sortBy('t')->values()->map(fn ($c) => array_diff_key($c, ['t' => 0, 'example' => 0]))->all();
        return ['revisions' => $revisions, 'children' => $children];
    }

    /** Ages for the lineage map's heat map (#618), in game days */
    public const MAP_AGES = [1, 2, 3, 5, 7, 10, 15, 20, 30, 45, 60, 90, 120, 180, 270, 365, 500, 750, 1000];

    /**
     * The whole lineage as a graph, for the map (#618): nodes (worked-out
     * revisions: id, company_id, push, example, label, day (game day; null
     * for examples), size, new, parents [[id, share], …]), lanes (one per
     * company or example, families together: each company follows the one
     * its release mainly came from, in release order: name, company_id,
     * example, url), ages, and each company's net wealth at those ages
     * (Leaderboard::atAges: wealth, gap, rank)
     */
    public static function graph(): array
    {
        if (!Schema::hasTable('code_revisions')) {
            return ['nodes' => [], 'lanes' => [], 'ages' => self::MAP_AGES, 'companies' => []];
        }
        $epoch = (float) (DB::table('game_clock')->where('id', 1)->value('epoch') ?? 0);
        $revs = DB::table('code_revisions')->whereNotNull('new_share')->orderByRaw('game_t IS NOT NULL, game_t, id')->get()->keyBy('id');
        $parents = DB::table('code_parents')->orderBy('revision_id')->orderBy('rank_no')->get()->groupBy('revision_id');
        $names = DB::table('companies')->whereIn('company_id', $revs->pluck('company_id')->filter()->unique()->all())
            ->pluck('name', 'company_id')->all();

        // Lanes: examples first, each followed by the families descended from it
        $laneOf = fn ($r) => $r->example !== null ? 'x:' . $r->example : 'c:' . $r->company_id;
        $kids = [];
        $roots = [];
        foreach ($revs as $r) {
            if ($r->example !== null) {
                $roots[] = $laneOf($r);
                continue;
            }
            if ((int) $r->push_number !== 0) {
                continue;
            }
            $main = $parents[$r->id][0] ?? null;
            if ($main && isset($revs[$main->parent_id])) {
                $kids[$laneOf($revs[$main->parent_id])][] = $laneOf($r);
            } else {
                $roots[] = $laneOf($r);
            }
        }
        $order = [];
        $visit = function ($lane) use (&$visit, &$order, $kids) {
            if (isset($order[$lane])) {
                return;
            }
            $order[$lane] = count($order);
            foreach ($kids[$lane] ?? [] as $k) {
                $visit($k);
            }
        };
        foreach ($roots as $lane) {
            $visit($lane);
        }
        foreach ($revs as $r) {
            $visit($laneOf($r));   // a company whose release isn't worked out yet: at the end
        }
        $lanes = [];
        foreach (array_keys($order) as $lane) {
            [$kind, $key] = explode(':', $lane, 2);
            if ($kind === 'x') {
                [$dir, $name] = explode('/', $key, 2);
                $lanes[] = ['name' => '[' . $name . ']', 'company_id' => null, 'example' => $key,
                    'url' => "https://github.com/Darwin-River/Ex-Machinis/blob/main/examples/{$key}.lua"];
            } else {
                $lanes[] = ['name' => $names[$key] ?? ('company ' . $key), 'company_id' => (int) $key, 'example' => null,
                    'url' => url('/companies/' . $key)];
            }
        }
        $nodes = [];
        foreach ($revs as $r) {
            $label = $r->example !== null ? '[' . explode('/', $r->example, 2)[1] . ']'
                : ($names[$r->company_id] ?? 'company ' . $r->company_id) . ((int) $r->push_number ? ' push ' . $r->push_number : '');
            $nodes[] = ['id' => (int) $r->id, 'company_id' => $r->company_id === null ? null : (int) $r->company_id,
                'push' => (int) $r->push_number, 'example' => $r->example, 'label' => $label, 'lane' => $order[$laneOf($r)],
                'day' => $r->game_t === null ? null : round(((int) $r->game_t - $epoch) / 86400, 3),
                'size' => (int) $r->size, 'new' => round((float) $r->new_share, 3),
                'parents' => ($parents[$r->id] ?? collect())->filter(fn ($p) => isset($revs[$p->parent_id]))
                    ->map(fn ($p) => [(int) $p->parent_id, round((float) $p->share, 3)])->values()->all()];
        }
        $wealth = [];
        foreach (Leaderboard::atAges(self::MAP_AGES) as $co => $w) {
            if (isset($names[$co])) {
                $wealth[$co] = $w;
            }
        }
        return ['nodes' => $nodes, 'lanes' => $lanes, 'ages' => self::MAP_AGES, 'companies' => $wealth];
    }
}
