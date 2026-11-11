<?php

// The "Keep the lights on" page, /support (#522). Update once a month:
// see documents/sustainability-playbook.md, "The monthly hour".
return [
    // The Ko-fi page's name, as in https://ko-fi.com/<name>. Blank until the
    // account exists: the page then says Ko-fi is coming soon.
    'kofi' => env('KOFI_NAME', ''),

    // What the game costs to run each month, in US dollars
    'costs' => [
        'Server (with backups)' => 25,
        'Domain name' => 2,
        'AI subscription (most of the building and upkeep)' => 20,
    ],

    // What supporters gave last month, in US dollars, and which month that was
    'covered' => 0,
    'month' => '',
];
