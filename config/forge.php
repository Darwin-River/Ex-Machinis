<?php

// Public Forge world settings (#324 step 6; no email since #367 step 5)
return [
    // The Lua syntax checker (#380: game-engine/src/bin/lua-check, built
    // with the engine): when it is there, a Lua script's syntax errors are
    // reported by the check, the Release page and the API before release
    'lua_check' => env('LUA_CHECK', base_path('game-engine/src/bin/lua-check')),
    // The account trading script's sandbox (#768), and how often it may run (real seconds)
    'lua_trade' => env('LUA_TRADE', base_path('game-engine/src/bin/lua-trade')),
    'trade_script_every' => (int) env('FORGE_TRADE_SCRIPT_EVERY', 120),
    // A drone script's test drive (#1007): runs it against a snapshot of the world, changing nothing
    'lua_drive' => env('LUA_DRIVE', base_path('game-engine/src/bin/lua-drive')),

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

    // The new rules (#635): player accounts, startups, locked companies and
    // a bank for shares. Off on the live site until the reset (#642); the
    // pages and API calls they add answer 404 while it is off
    'new_rules' => (bool) env('FORGE_NEW_RULES', false),
    // A new player account's credits that can only buy shares (#636)
    // Starting credits for shares (#638); none since #928: a player's credits
    // come from their shares, the first from maturing a startup
    'player_share_credits' => (int) env('FORGE_PLAYER_SHARE_CREDITS', 0),
    // Credits a player needs to back new loans (#928)
    'lend_min_credits' => (int) env('FORGE_LEND_MIN_CREDITS', 5000),
    // For a trial without a mail service (#642): new accounts count as
    // verified at once and get their share credits. Turn it off, with mail
    // set up (hosting.md), before the game opens for real
    'trial_auto_verify' => (bool) env('FORGE_TRIAL_AUTO_VERIFY', false),
    // Shortest password an account may have
    'player_password_min' => 8,
    // New accounts one address may make in an hour (#636: e-mail isn't
    // verified, so this is the cheap guard against piles of extra accounts)
    'player_signups_per_hour' => (int) env('FORGE_PLAYER_SIGNUPS_PER_HOUR', 3),
    // Most pupils' accounts in one class group
    'class_group_max' => (int) env('FORGE_CLASS_GROUP_MAX', 40),
    // Claude players (#1025): the accounts that may make keys for them (names,
    // comma-separated), and the most Claude players each operator may have
    'operators' => array_values(array_filter(array_map('trim', explode(',', (string) env('FORGE_OPERATORS', ''))))),
    'bot_max' => (int) env('FORGE_BOT_MAX', 30),

    // Startups (#637): every company starts as one, lent its hull and its
    // starting credits (starting_credits) by the bank, plus a fee; it owes all
    // three until it matures. Its trading limits are in house_rules. #658:
    // the hull counts at about what the House pays for a kit (999), and the
    // fee is smaller, so a working startup can mature in 10 to 20 game days
    // (round 65's companies held 2,900 on average at 10 game days)
    'startup_max' => (int) env('FORGE_STARTUP_MAX', 5),
    'startup_hull_value' => (int) env('FORGE_STARTUP_HULL_VALUE', 1000),
    'startup_fee' => (int) env('FORGE_STARTUP_FEE', 200),
    // What reprogramming a startup adds to its debt (its first push is free)
    'startup_push_fee' => (int) env('FORGE_STARTUP_PUSH_FEE', 100),
    // Credits a startup must hold above its debt to mature (#664), so it
    // isn't left broke
    'startup_mature_margin' => (int) env('FORGE_STARTUP_MATURE_MARGIN', 500),
    // Game days a closed startup's slot stays taken
    'startup_slot_days' => 1,
    // Startups a guest (no account) may start from one address in a real day
    'startup_guest_per_day' => (int) env('FORGE_STARTUP_GUEST_PER_DAY', 5),

    // Shares and the bank (#638). Cash-out value counts each working drone's
    // hull at startup_hull_value, falling to 0 over hull_life_days game days,
    // and nothing for a drone with no fuel that bought none in stranded_days
    'hull_life_days' => (int) env('FORGE_HULL_LIFE_DAYS', 365),
    'stranded_days' => 3,
    // Game days the bank takes to pay for shares sold to it
    'bank_pay_days' => (int) env('FORGE_BANK_PAY_DAYS', 30),
    // The game's history within a disk budget (#813, after #811): above this
    // percent of the disk in use, the keeper prunes the oldest archived
    // events (summed into drone_days first). 0 never prunes
    'history_budget_percent' => (int) env('FORGE_HISTORY_BUDGET_PERCENT', 60),
    // A folder on the disk to measure (the server's disk, as the app sees it)
    'history_disk_path' => env('FORGE_HISTORY_DISK_PATH', base_path()),
    // The bank sells a share at its cash-out value plus the dividends a share
    // was paid over this many game days (#781: 30 prepaid a month of dividends,
    // so buying from the bank barely paid)
    'bank_premium_days' => (int) env('FORGE_BANK_PREMIUM_DAYS', 10),
    // A standing share order that fills against another player's order earns
    // its owner this many thousandths of the trade from the bank, once it has
    // rested this many game seconds (#782: keep orders on the book)
    'rebate_permille' => (int) env('FORGE_REBATE_PERMILLE', 5),
    'rebate_rest_game_seconds' => (int) env('FORGE_REBATE_REST_GAME_SECONDS', 3600),
    // Players trade shares between these times the bank's selling price
    'share_range_low' => (float) env('FORGE_SHARE_RANGE_LOW', 0.8),
    'share_range_high' => (float) env('FORGE_SHARE_RANGE_HIGH', 1.25),
    // Thousandths of a matured company's daily profit paid out as dividends
    // (#667: a quarter, so maturing a company pays its founder out more slowly)
    'dividend_permille' => (int) env('FORGE_DIVIDEND_PERMILLE', 500),

];
