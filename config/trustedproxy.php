<?php

return [

    /*
    | Proxies whose X-Forwarded-* headers are trusted, as a comma-separated
    | list of IPs/CIDRs, or '*' for whichever proxy is calling. Set this when
    | the site runs behind a reverse proxy that terminates HTTPS (as in the
    | Docker setup), so Laravel generates https:// URLs.
    */
    'proxies' => env('TRUSTED_PROXIES'),

];
