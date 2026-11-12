<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * What the price and volume chart (#595, #597) needs besides
 * /api/v1/history's rows: the places in the order that sets their colours,
 * and today's game day
 */
class MarketHistory
{
    /** ['places' => place IDs that trades happen at, in ID order, 'today' => game day] */
    public static function frame(): array
    {
        $places = DB::table('trades')->whereNotNull('location')->distinct()->orderBy('location')->pluck('location')
            ->map(fn ($id) => (int) $id);
        $epoch = (int) DB::table('game_clock')->where('id', 1)->value('epoch');
        return ['places' => $places, 'today' => intdiv(max(0, World::gameNow() - $epoch), 86400)];
    }
}
