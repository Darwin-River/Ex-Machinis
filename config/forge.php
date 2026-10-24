<?php

// Public Forge world settings (#324 step 6)
return [
    // false switches the email game off: no mail is read or sent, players
    // release scripts on the Release page and read their drones' replies there
    'email' => filter_var(env('GAME_EMAIL', true), FILTER_VALIDATE_BOOLEAN),

    // true: an account can only be opened with an invitation code
    'invite_only' => filter_var(env('INVITE_ONLY', false), FILTER_VALIDATE_BOOLEAN),

    // A released company's starting bank account
    'starting_credits' => 1000,

    // Longest script the Release page accepts (characters)
    'max_script_length' => 16000,
];
