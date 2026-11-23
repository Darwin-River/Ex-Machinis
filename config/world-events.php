<?php

/*
| The world's events (#720): rule changes, outages and repairs that moved
| every company's figures at once, drawn as marks on the site's time charts
| and served by GET /api/v1/world-events. Add one here when a change lands.
|
| Each event has:
|   key     a short, stable name (the API's id for it)
|   kind    'rule' (a rule change), 'outage' (the world stopped) or 'repair'
|   label   a few words, drawn on the chart by its mark
|   text    one sentence: what happened, and what it did to the figures
|   issue   the GitHub issue, or null
| and when it happened, one of:
|   day (and to_day for a stretch of days)   game days, fixed
|   migration  a migration in game-engine/sql/migrations: the game time it
|              was applied on this server (schema_version), which is when
|              the change took effect there (use it when the live world's
|              day isn't known yet). A day given too is used only on a
|              server with no record of the migration.
|   reset      true: the moment the world was reset for the new rules
|              (the companies ended with end_cause "reset")
| An event whose time this server can't work out is left out.
*/
return [
    [
        'key' => 'new-rules-reset', 'kind' => 'rule', 'reset' => true, 'issue' => 642,
        'label' => 'New rules: world reset',
        'text' => 'The playtests\' world ended and a fresh one opened under the new rules: player accounts, startups that mature, and shares traded with the bank.',
    ],
    [
        'key' => 'fleet-cap', 'kind' => 'rule', 'day' => 2134, 'issue' => 682,
        'label' => '8-drone cap',
        'text' => 'A company may have 8 working drones at most; those past it were scrapped with what they held, so wealth and cash-out fell (Bank Maker went from 106 drones to 8, its value from about 505,000 credits to 177,000).',
    ],
    [
        'key' => 'outage-full-disk', 'kind' => 'outage', 'day' => 2161, 'to_day' => 2170, 'issue' => 711,
        'label' => 'Outage',
        'text' => 'A full disk stopped the world: no trades and no fresh daily figures from {from} to {to}, so the charts are flat or empty there.',
    ],
    [
        'key' => 'cash-out-recorded', 'kind' => 'rule', 'day' => 2176, 'issue' => 699,
        'label' => 'Cash-out recorded',
        'text' => 'The world began keeping each matured company\'s cash-out value every game day: there is no cash-out line before this.',
    ],
    [
        'key' => 'stuck-tools', 'kind' => 'repair', 'migration' => '0067_free_stuck_tools', 'issue' => 711,
        'label' => 'Stuck tools freed',
        'text' => 'Tools left locked by actions the outage aborted were given back, so makers and miners that had failed every make or mine with -2 could work again.',
    ],
    [
        'key' => 'steady-cash-out', 'kind' => 'rule', 'migration' => '0068_house_best_bids', 'issue' => 685,
        'label' => 'Steady cash-out',
        'text' => 'Cash-out counts goods at a 7-day average of the House\'s best bid anywhere, not the bid where each drone happens to be, so it no longer swings 20 to 30% a game day.',
    ],
    [
        'key' => 'outage-overload', 'kind' => 'outage', 'day' => 3074, 'to_day' => 3249, 'issue' => 742,
        'label' => 'Outage',
        'text' => 'The world stopped while the database was overloaded and then repaired (#731, #736): no trades and no daily figures from {from} to {to}, so the charts are empty there.',
    ],
    [
        'key' => 'crowded-sites', 'kind' => 'rule', 'migration' => '0101_crowded_sites', 'issue' => 974,
        'label' => 'Crowded sites yield less',
        'text' => 'Past 10 drones mining a mineral at a place in a game hour, each run there yields less (never nothing; a startup counts as half a drone), so miners\' income at the busiest sites fell and quieter sites paid more.',
    ],
    [
        'key' => 'made-goods-spread', 'kind' => 'rule', 'migration' => '0102_made_goods_spread', 'issue' => 964,
        'label' => 'Made goods pay more',
        'text' => 'The House buys alloys, polymers, parts and systems at 85% of their value (it was 75%) and sells parts and systems at 1.3 times (it was 1.5), so making and selling goods paid more, and buying parts to make systems began to pay.',
    ],
    [
        'key' => 'moon-full-price', 'kind' => 'rule', 'migration' => '0102_made_goods_spread', 'issue' => 963,
        'label' => 'Moon pays full price',
        'text' => 'The Moon Depot pays full price for ores and refined goods (it paid 15% less), still selling them cheaper, so miners and refiners selling at the Moon earned about a fifth more.',
    ],
];
