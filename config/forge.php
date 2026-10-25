<?php

// Public Forge world settings (#324 step 6)
return [
    // false switches the email game off: no mail is read or sent; anyone
    // releases scripts on the Release page (no accounts since #367 step 4)
    // and reads a drone's replies on its page
    'email' => filter_var(env('GAME_EMAIL', true), FILTER_VALIDATE_BOOLEAN),

    // true shows the Release page's Test button, which runs a script in the
    // test world (its own database and engines, with a faster clock)
    'test_world' => filter_var(env('TEST_WORLD', false), FILTER_VALIDATE_BOOLEAN),

    // Test runs the test world keeps (#367 step 3: there are no accounts, so
    // all together): starting another stops and removes the oldest
    'test_runs_kept' => 100,

    // Fuel in a new drone's hold (#324 step 8): actions and computing burn it
    'starting_fuel' => 100,

    // A released company's starting bank account
    'starting_credits' => 1000,

    // Longest script the Release page accepts (characters)
    'max_script_length' => 16000,
];
