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
}
