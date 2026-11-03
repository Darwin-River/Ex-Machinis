<?php

// Public Forge world settings (#324 step 6; no email since #367 step 5)
return [
    // true shows the Release page's Test button, which runs a script in the
    // test world (its own database and engines, with a faster clock)
    'test_world' => filter_var(env('TEST_WORLD', false), FILTER_VALIDATE_BOOLEAN),

    // Test runs the test world keeps (#367 step 3: there are no accounts, so
    // all together): starting another stops and removes the oldest
    'test_runs_kept' => 100,

    // Real minutes a test run keeps running (#380 round 34): starting another
    // stops the older ones, so a new run doesn't queue behind them. Their
    // results stay readable. 0 lets them run on.
    'test_run_minutes' => (int) env('TEST_RUN_MINUTES', 30),

    // The Lua syntax checker (#380: game-engine/src/bin/lua-check, built
    // with the engine): when it is there, a Lua script's syntax errors are
    // reported by the check, the Release page and the API before release
    'lua_check' => env('LUA_CHECK', base_path('game-engine/src/bin/lua-check')),

    // Fuel in a new drone's hold (#324 step 8): actions and computing burn it
    'starting_fuel' => 100,
    // Instructions a drone runs for 1 fuel: game-engine.conf's POWER_INSTRUCTIONS
    'power_instructions' => 200000,

    // A released company's starting bank account
    'starting_credits' => 1000,

    // Longest script the Release page accepts (characters)
    'max_script_length' => 16000,
];
