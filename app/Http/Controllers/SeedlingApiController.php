<?php

namespace App\Http\Controllers;

use App\Services\Players;
use App\Services\Seedlings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seedlings in the API (#637), with an account's token
 * ("Authorization: Bearer <token>"). Behind the new-rules switch (#635).
 */
class SeedlingApiController extends Controller
{
    private function answer(array $result, int $ok = 200): JsonResponse
    {
        if (!$result['ok']) {
            return response()->json(['error' => $result['error']], $result['status']);
        }
        unset($result['ok']);
        if (isset($result['agent'])) {
            $result['drone'] = (int) $result['agent']->agent_id;
            unset($result['agent']);
        }
        return response()->json($result, $ok);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
    }

    /** GET /api/v1/seedlings: the account's seedlings and matured companies */
    public function index(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        return $user ? response()->json(Seedlings::of($user)) : $this->unauthorized();
    }

    /** POST /api/v1/seedlings/claim {code} */
    public function claim(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        return $user ? $this->answer(Seedlings::claim($user, (string) $request->input('code', ''))) : $this->unauthorized();
    }

    /** POST /api/v1/seedlings/{id}/push {script} */
    public function reprogram(Request $request, int $id): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return $this->unauthorized();
        }
        $script = str_replace("\r\n", "\n", (string) $request->input('script', ''));
        if ($script === '' || mb_strlen($script) > (int) config('forge.max_script_length')) {
            return response()->json(['error' => 'Send the new code as script.'], 422);
        }
        return $this->answer(Seedlings::reprogram($user, $id, $script));
    }

    /** POST /api/v1/seedlings/{id}/close */
    public function close(Request $request, int $id): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        return $user ? $this->answer(Seedlings::close($user, $id)) : $this->unauthorized();
    }

    /** POST /api/v1/seedlings/{id}/mature */
    public function mature(Request $request, int $id): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        return $user ? $this->answer(Seedlings::mature($user, $id)) : $this->unauthorized();
    }
}
