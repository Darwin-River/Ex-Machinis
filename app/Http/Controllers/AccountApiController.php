<?php

namespace App\Http\Controllers;

use App\Services\Players;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player accounts in the API (#636): make one, log in for a token, and read
 * it. A program sends the token as "Authorization: Bearer <token>". Behind
 * the new-rules switch (#635).
 */
class AccountApiController extends Controller
{
    /**
     * POST /api/v1/accounts {name, email, password}: a new account and its
     * token; a link to verify the address is sent to it
     */
    public function create(Request $request): JsonResponse
    {
        $name = (string) $request->input('name', '');
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        if (!Players::maySignUp((string) $request->ip())) {
            return response()->json(['error' => 'Too many new accounts from your address this hour.'], 429);
        }
        try {
            $user = Players::create($name, $password, $email);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        Players::countSignUp((string) $request->ip());
        $sent = Players::sendVerification($user);
        return response()->json(['token' => Players::issueToken($user), 'verification_sent' => $sent] + Players::summary($user), 201);
    }

    /** POST /api/v1/login {name, password}: a new token (the old one stops working) */
    public function login(Request $request): JsonResponse
    {
        $user = Players::authenticate((string) $request->input('name', ''), (string) $request->input('password', ''));
        if (!$user) {
            return response()->json(['error' => 'That name and password don\'t match an account.'], 401);
        }
        return response()->json(['token' => Players::issueToken($user)] + Players::summary($user));
    }

    /** POST /api/v1/account/verify: sends the verification link again */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
        }
        if (Players::verified($user)) {
            return response()->json(['error' => 'The account is verified already.'], 409);
        }
        $key = 'verify-mail|' . $user->user_id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['error' => 'The link has been sent several times this hour.'], 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 3600);
        return response()->json(['verification_sent' => Players::sendVerification($user)]);
    }

    /** GET /api/v1/account: the token's account */
    public function show(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
        }
        return response()->json(Players::summary($user));
    }

    /**
     * The e-mail notice settings (#761), as on the account page (#836: the
     * API had no route for them): GET reads them, POST {notices: [kinds]}
     * turns those on and the rest off
     */
    public function notices(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
        }
        if (!\App\Services\Notices::available()) {
            return response()->json(['error' => 'E-mail notices are off on this server.'], 404);
        }
        if ($request->isMethod('post')) {
            if (!Players::verified($user) || \Illuminate\Support\Facades\DB::table('users')->where('user_id', $user->user_id)->value('class_group_id') !== null) {
                return response()->json(['error' => 'E-mail notices need an account with a verified e-mail address.'], 409);
            }
            $asked = (array) $request->input('notices', []);
            $unknown = array_values(array_diff($asked, array_keys(\App\Services\Notices::KINDS)));
            if ($unknown) {
                return response()->json(['error' => 'No such notice: ' . implode(', ', $unknown) . '.',
                    'kinds' => array_keys(\App\Services\Notices::KINDS)], 422);
            }
            \App\Services\Notices::save($user, array_values($asked));
        }
        return response()->json(['notices' => \App\Services\Notices::settings($user), 'kinds' => \App\Services\Notices::KINDS]);
    }
}
