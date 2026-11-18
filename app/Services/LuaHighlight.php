<?php

namespace App\Services;

/**
 * Lua, coloured (#848): a script as escaped HTML, each token in a span whose
 * class says what it is. The colours are the site's (.lua in
 * resources/assets/sass/site/_components.scss), so every page that shows
 * code through here looks the same:
 *
 *   k  keywords (function, if, end…)      l  true, false and nil
 *   s  strings                            n  numbers
 *   c  comments                           o  operators and punctuation
 *   b  Lua's own functions and tables     g  the game library's names
 *   f  the name a function is defined under
 *
 * Nothing is lost: the text of the HTML is the script, spaces and all, so
 * code that doesn't parse still shows as written.
 */
class LuaHighlight
{
    private const KEYWORDS = ['and', 'break', 'do', 'else', 'elseif', 'end', 'for', 'function', 'goto', 'if', 'in',
        'local', 'not', 'or', 'repeat', 'return', 'then', 'until', 'while'];
    private const LITERALS = ['true', 'false', 'nil'];
    /** Lua's own globals that a drone's sandbox keeps */
    private const BUILTINS = ['assert', 'error', 'getmetatable', 'ipairs', 'next', 'pairs', 'pcall', 'rawequal',
        'rawget', 'rawlen', 'rawset', 'select', 'setmetatable', 'tonumber', 'tostring', 'type', 'unpack', 'xpcall',
        'string', 'table', 'math', 'utf8', 'coroutine', 'os', '_G', '_ENV', 'self'];
    /** The engine's own functions (lua_drone.c), under the library */
    private const PRIMITIVES = ['print', 'report', 'perform', 'query', 'set_status'];
    private const OPERATORS = ['...', '..', '==', '~=', '<=', '>=', '//', '::', '<<', '>>'];

    /**
     * A script as a <pre class="lua">, ready to put in a page. It is v-pre, so
     * Lua's {{ }} isn't read by Vue (#592). $attributes are the pre's others
     * (a class is added to lua)
     */
    public static function block(?string $src, array $attributes = []): string
    {
        $attributes['class'] = trim('lua ' . ($attributes['class'] ?? ''));
        $html = '<pre';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . e($value) . '"';
        }
        return $html . ' v-pre>' . self::html((string) $src) . '</pre>';
    }

    /** A script as escaped HTML with a span for each token but spaces and names of its own */
    public static function html(string $src): string
    {
        $out = '';
        $afterFunction = false;   // the name after `function` is the one it defines
        $afterDot = false;        // a field (x.fuel) is the script's, not the library's
        foreach (self::tokens($src) as [$type, $text]) {
            $class = match ($type) {
                'name' => self::nameClass($text, $afterFunction, $afterDot),
                'space' => '',
                default => $type,
            };
            if ($type !== 'space' && $type !== 'c') {
                $afterFunction = ($type === 'k' && $text === 'function') || ($afterFunction && ($type === 'name' || $text === '.' || $text === ':'));
                $afterDot = $type === 'o' && ($text === '.' || $text === ':');
            }
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $out .= $class === '' ? $text : '<span class="' . $class . '">' . $text . '</span>';
        }
        return $out;
    }

    /**
     * Colours the Lua blocks (```lua) of HTML rendered from Markdown, as the
     * players' manual and the blog are. Other blocks (a drone's output) stay plain
     */
    public static function markdownBlocks(string $html): string
    {
        return preg_replace_callback('/<pre><code class="language-lua">(.*?)<\/code><\/pre>/s',
            fn ($m) => '<pre class="lua" v-pre><code class="language-lua">'
                . self::html(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</code></pre>', $html);
    }

    private static function nameClass(string $name, bool $afterFunction, bool $afterDot): string
    {
        if ($afterFunction) {
            return 'f';
        }
        if ($afterDot) {
            return '';
        }
        if (in_array($name, self::BUILTINS, true)) {
            return 'b';
        }
        if (in_array($name, self::PRIMITIVES, true) || isset(CodeLineage::library()[$name])) {
            return 'g';
        }
        return '';
    }

    /** Every piece of a script, in order: [type (k, l, s, n, c, o, name, space), text] */
    public static function tokens(string $src): array
    {
        $out = [];
        $n = strlen($src);
        $i = 0;
        $long = fn (int $at) => preg_match('/\G\[(=*)\[/', $src, $m, 0, $at) ? [']' . $m[1] . ']', strlen($m[0])] : null;
        $upTo = function (string $close, int $from) use ($src, $n) {
            $end = strpos($src, $close, $from);
            return $end === false ? $n : $end + strlen($close);
        };
        while ($i < $n) {
            $c = $src[$i];
            if (ctype_space($c)) {
                $end = $i + strspn($src, " \t\r\n\f\v", $i);
                $out[] = ['space', substr($src, $i, $end - $i)];
            } elseif ($c === '-' && substr($src, $i, 2) === '--') {
                $l = $long($i + 2);
                $end = $l ? $upTo($l[0], $i + 2 + $l[1]) : (strpos($src, "\n", $i) === false ? $n : strpos($src, "\n", $i));
                $out[] = ['c', substr($src, $i, $end - $i)];
            } elseif ($c === '[' && ($l = $long($i))) {
                $end = $upTo($l[0], $i + $l[1]);
                $out[] = ['s', substr($src, $i, $end - $i)];
            } elseif ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $src[$j] !== $c && $src[$j] !== "\n") {
                    $j += $src[$j] === '\\' ? 2 : 1;
                }
                $end = min($j < $n && $src[$j] === $c ? $j + 1 : $j, $n);
                $out[] = ['s', substr($src, $i, $end - $i)];
            } elseif (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($src[$i + 1]))) {
                preg_match('/\G(0[xX][0-9a-fA-F.]*([pP][+-]?\d+)?|[0-9.]+([eE][+-]?\d+)?)/', $src, $m, 0, $i);
                $end = $i + strlen($m[0]);
                $out[] = ['n', $m[0]];
            } elseif (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $src, $m, 0, $i);
                $end = $i + strlen($m[0]);
                $out[] = [in_array($m[0], self::KEYWORDS, true) ? 'k' : (in_array($m[0], self::LITERALS, true) ? 'l' : 'name'), $m[0]];
            } elseif (ord($c) >= 128) {
                // Bytes that aren't Lua (a letter with an accent outside a string) are left plain, whole
                $end = $i + 1;
                while ($end < $n && ord($src[$end]) >= 128) {
                    $end++;
                }
                $out[] = ['space', substr($src, $i, $end - $i)];
            } else {
                $op = $c;
                foreach (self::OPERATORS as $o) {
                    if (substr_compare($src, $o, $i, strlen($o)) === 0) {
                        $op = $o;
                        break;
                    }
                }
                $out[] = ['o', $op];
                $end = $i + strlen($op);
            }
            $i = $end;
        }
        return $out;
    }
}
