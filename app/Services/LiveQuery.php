<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Live data on the Machine page (#380): runs one of the game's queries on
 * the live world as a drone would, and lays its rows out in the bytes the
 * engine writes into a drone's memory (db_run_vm_query in db.c), so a
 * player can try a market-analysis word on real data in the browser and
 * then put the same word in a company design.
 *
 * Only the game's own queries run (the queries table): read-only SELECTs.
 * A player supplies their numbers, never SQL.
 */
class LiveQuery
{
    /** What query leaves when it fails (vm_extension.c) */
    public const NOT_ENOUGH_PARAMETERS = -1;
    public const FAILED = -2;
    public const UNKNOWN = -3;

    /** Every query's parameter count and which parameters are strings, for the page */
    public static function catalog(): array
    {
        $out = [];
        foreach (DB::table('queries')->orderBy('id')->get(['id', 'name', 'parameters', 'script']) as $q) {
            preg_match_all('/\[string_(\d)\]/', (string) $q->script, $m);
            $out[(int) $q->id] = ['name' => $q->name, 'parameters' => (int) $q->parameters,
                'strings' => array_map('intval', array_unique($m[1]))];
        }
        return $out;
    }

    /** Every protocol's parameter count, so the page takes perform's arguments as the engine does */
    public static function protocols(): array
    {
        $out = [];
        foreach (DB::table('protocols')->orderBy('id')->get(['id', 'name', 'parameters']) as $p) {
            $out[(int) $p->id] = ['name' => $p->name, 'parameters' => (int) $p->parameters];
        }
        return $out;
    }

    /**
     * Runs query $id as drone $drone would, with its parameters in the order
     * the engine takes them off the stack (the first is [value_1]) and
     * strings for its [string_n] parameters. Returns the code query leaves
     * (rows stored, or a negative error), the bytes for the results array of
     * $size bytes, and the rows as text for the page to show.
     */
    public static function run(int $id, int $drone, int $size, array $values, array $strings = []): array
    {
        $query = DB::table('queries')->where('id', $id)->first();
        if (!$query) {
            return ['code' => self::UNKNOWN, 'bytes' => '', 'rows' => []];
        }
        if (count($values) < (int) $query->parameters) {
            return ['code' => self::NOT_ENOUGH_PARAMETERS, 'bytes' => '', 'rows' => []];
        }
        $sql = self::fill((string) $query->script, $drone, $values, $strings);
        if ($sql === null) {
            return ['code' => self::FAILED, 'bytes' => '', 'rows' => []];
        }

        try {
            $statement = DB::connection()->getPdo()->query($sql);
        } catch (\Throwable $e) {
            return ['code' => self::FAILED, 'bytes' => '', 'rows' => []];
        }
        $fields = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $meta = $statement->getColumnMeta($i);
            $fields[] = ['name' => $meta['name'], 'type' => strtoupper($meta['native_type'] ?? 'VAR_STRING')];
        }
        $rows = $statement->fetchAll(\PDO::FETCH_NUM);
        if (!$rows) {
            return ['code' => self::FAILED, 'bytes' => '', 'rows' => []];
        }

        $epoch = (int) round((float) DB::table('game_clock')->where('id', 1)->value('epoch'));
        $bytes = '';
        $stored = 0;
        $shown = [];
        foreach ($rows as $row) {
            $out = '';
            foreach ($fields as $i => $field) {
                $out .= self::field($field, $row[$i], $epoch);
            }
            if (strlen($bytes) + strlen($out) > $size) {
                break;   // only whole rows, as the engine stores them
            }
            $bytes .= $out;
            $stored++;
            $shown[] = array_combine(array_column($fields, 'name'), array_map(fn ($v) => $v, $row));
        }
        return ['code' => $stored, 'bytes' => base64_encode($bytes), 'rows' => $shown];
    }

    /** The query's script with its tags filled in, as vm_get_new_tag_value does */
    private static function fill(string $sql, int $drone, array $values, array $strings): ?string
    {
        $values = array_map(fn ($v) => self::signed((int) $v), $values);
        $tags = ['[drone_id]' => (string) $drone, '[game_now]' => (string) World::gameNow()];
        for ($n = 1; $n <= 9; $n++) {
            if (isset($values[$n - 1])) {
                $tags["[value_$n]"] = (string) $values[$n - 1];
            }
        }
        for ($n = 1; $n <= 4; $n++) {
            // A double: this parameter is its high cell, the next its low
            if (isset($values[$n - 1], $values[$n])) {
                $bits = (($values[$n - 1] & 0xFFFF) << 16) | ($values[$n] & 0xFFFF);
                $tags["[dvalue_$n]"] = (string) ($bits >= 0x80000000 ? $bits - 0x100000000 : $bits);
            }
            if (isset($strings[$n])) {
                // Only characters that can't change the query (#322)
                if (!preg_match('/^[A-Za-z0-9 ._-]*$/', (string) $strings[$n]) || strlen($strings[$n]) > 255) {
                    return null;
                }
                $tags["[string_$n]"] = (string) $strings[$n];
            }
        }
        foreach ($tags as $tag => $value) {
            $sql = str_replace($tag, $value, $sql);
        }
        // A tag left over has no value here (a missing parameter, or the
        // drone's light time, which the website doesn't work out)
        return preg_match('/\[(value|dvalue|string)_\d\]|\[time_delay\]/', $sql) ? null : $sql;
    }

    /** One field's bytes, as db_run_vm_query writes them */
    private static function field(array $field, $value, int $epoch): string
    {
        $type = $field['type'];
        $text = $value === null ? '' : (string) $value;
        if (in_array($type, ['STRING', 'VAR_STRING', 'VARCHAR', 'BLOB', 'TINY_BLOB', 'MEDIUM_BLOB', 'LONG_BLOB', 'ENUM', 'SET'], true)) {
            $text = substr($text, 0, 255);
            return chr(strlen($text)) . $text;     // a counted string
        }
        if (in_array($type, ['DATETIME', 'TIMESTAMP', 'DATE'], true)) {
            // Game day and even seconds into it, from the world's day 0
            $since = $value === null ? 0 : max(0, (new \DateTime($text, new \DateTimeZone('UTC')))->getTimestamp() - $epoch);
            return self::cell(intdiv($since, 86400)) . self::cell(intdiv($since % 86400, 2));
        }
        if (in_array($type, ['TINY', 'SHORT', 'LONG', 'INT24', 'LONGLONG', 'DECIMAL', 'NEWDECIMAL', 'FLOAT', 'DOUBLE', 'YEAR', 'BIT'], true)) {
            $number = (int) round((float) $text);
            if (preg_match('/^d_/i', $field['name'])) {
                // Money and volumes: 32 bits, high cell first, clamped
                $number = max(-2147483648, min(2147483647, $number));
                $bits = $number & 0xFFFFFFFF;
                return self::cell($bits >> 16) . self::cell($bits & 0xFFFF);
            }
            return self::cell($number);
        }
        return '';   // a type the engine doesn't store
    }

    /** A 16-bit cell, low byte first */
    private static function cell(int $value): string
    {
        return chr($value & 0xFF) . chr(($value >> 8) & 0xFF);
    }

    private static function signed(int $cell): int
    {
        $cell &= 0xFFFF;
        return $cell >= 0x8000 ? $cell - 0x10000 : $cell;
    }
}
