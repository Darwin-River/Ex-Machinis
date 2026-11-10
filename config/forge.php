<?php

// Public Forge world settings (#324 step 6; no email since #367 step 5)
return [
    // The Lua syntax checker (#380: game-engine/src/bin/lua-check, built
    // with the engine): when it is there, a Lua script's syntax errors are
    // reported by the check, the Release page and the API before release
    'lua_check' => env('LUA_CHECK', base_path('game-engine/src/bin/lua-check')),

    // Fuel in a new drone's hold (#324 step 8): actions and computing burn it
    'starting_fuel' => 100,
    // Instructions a drone runs for 1 fuel: game-engine.conf's POWER_INSTRUCTIONS
    'power_instructions' => 200000,

    // A released company's starting bank account (#506: 3,000, the 1,000 it
    // had and the 2,000 a kit costs at the House; it buys its own equipment)
    'starting_credits' => 3000,

    // Longest script the Release page accepts (characters)
    'max_script_length' => 16000,
];
