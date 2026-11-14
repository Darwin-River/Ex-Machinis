<?php

namespace App\Http\Middleware;

use Closure;

/**
 * The new rules' pages and API calls (#635) exist only while
 * FORGE_NEW_RULES is on: until the reset (#642), they answer 404.
 */
class NewRules
{
    public function handle($request, Closure $next)
    {
        if (!config('forge.new_rules')) {
            abort(404);
        }
        return $next($request);
    }
}
