<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Aborts with 403 unless $key matches the configured cron key.
     * Always refuses when no cron key is configured.
     * @param string $key key taken from the URL
     */
    protected function requireCronKey($key)
    {
        $expected = (string) config('app.cron_key');

        if ($expected === '' || !hash_equals($expected, (string) $key)) {
            abort(403);
        }
    }

    /**
     * If there is a message as get parameter, sets it as flash message
     * $request Request object
     */
    public function querystringToFlash($request)
    {
        $message = $request->get('message');
        if ($message) {
            if ($request->get('message_type') != null)
                $request->session()->flash($request->get('message_type'), $message);
            else
                $request->session()->flash('success', $message);
        }
    }

}
