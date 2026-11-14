<?php

// Public Forge world settings (#324 step 6; no email since #367 step 5)
return [
    // The Lua syntax checker (#380: game-engine/src/bin/lua-check, built
    // with the engine): when it is there, a Lua script's syntax errors are
    // reported by the check, the Release page and the API before release
    'lua_check' => env('LUA_CHECK', base_path('game-engine/src/bin/lua-check')),

    // Fuel in a new drone's hold (#324 step 8): actions burn it
    'starting_fuel' => 100,
    // Instructions a drone ran for 1 fuel until #544 (game-engine.conf's
    // POWER_INSTRUCTIONS, now 0): old wrecks' post-mortems still use it
    'power_instructions' => 200000,

    // A released company's starting bank account (#506: 3,000, the 1,000 it
    // had and the 2,000 a kit costs at the House; it buys its own equipment)
    'starting_credits' => 3000,

    // Longest script the Release page accepts (characters)
    'max_script_length' => 16000,

    // Code pushes (#553): each costs this many thousandths of the company's
    // credits times the push's number (5%, 10%, 15%...), at most the cap
    'push_fee_permille' => (int) env('FORGE_PUSH_FEE_PERMILLE', 50),
    'push_fee_cap_permille' => (int) env('FORGE_PUSH_FEE_CAP_PERMILLE', 500),
    // Shortest password a release may set
    'push_password_min' => 6,

    // The new rules (#635): player accounts, seedlings, locked companies and
    // a bank for shares. Off on the live site until the reset (#642); the
    // pages and API calls they add answer 404 while it is off
    'new_rules' => (bool) env('FORGE_NEW_RULES', false),
    // A new player account's credits that can only buy shares (#636)
    'player_share_credits' => (int) env('FORGE_PLAYER_SHARE_CREDITS', 5000),
    // Shortest password an account may have
    'player_password_min' => 8,
    // New accounts one address may make in an hour (#636: e-mail isn't
    // verified, so this is the cheap guard against piles of extra accounts)
    'player_signups_per_hour' => (int) env('FORGE_PLAYER_SIGNUPS_PER_HOUR', 3),
    // Most pupils' accounts in one class group
    'class_group_max' => (int) env('FORGE_CLASS_GROUP_MAX', 40),

    // Seedlings (#637): every company starts as one, lent its hull and its
    // starting credits (starting_credits) by the bank, plus a fee; it owes all
    // three until it matures. Its trading limits are in house_rules
    'seedling_max' => (int) env('FORGE_SEEDLING_MAX', 5),
    'seedling_hull_value' => (int) env('FORGE_SEEDLING_HULL_VALUE', 2000),
    'seedling_fee' => (int) env('FORGE_SEEDLING_FEE', 500),
    // What reprogramming a seedling adds to its debt
    'seedling_push_fee' => (int) env('FORGE_SEEDLING_PUSH_FEE', 100),
    // Game days a closed seedling's slot stays taken
    'seedling_slot_days' => 1,
    // Seedlings a guest (no account) may start from one address in a real day
    'seedling_guest_per_day' => (int) env('FORGE_SEEDLING_GUEST_PER_DAY', 5),

    // Shares and the bank (#638). Cash-out value counts each working drone's
    // hull at seedling_hull_value, falling to 0 over hull_life_days game days,
    // and nothing for a drone with no fuel that bought none in stranded_days
    'hull_life_days' => (int) env('FORGE_HULL_LIFE_DAYS', 365),
    'stranded_days' => 3,
    // Game days the bank takes to pay for shares sold to it
    'bank_pay_days' => (int) env('FORGE_BANK_PAY_DAYS', 30),
    // Players trade shares between these times the bank's selling price
    'share_range_low' => (float) env('FORGE_SHARE_RANGE_LOW', 0.8),
    'share_range_high' => (float) env('FORGE_SHARE_RANGE_HIGH', 1.25),
    // Thousandths of a matured company's daily profit paid out as dividends
    'dividend_permille' => (int) env('FORGE_DIVIDEND_PERMILLE', 500),
];
