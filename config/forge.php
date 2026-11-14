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
];
