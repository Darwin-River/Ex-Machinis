<?php

namespace App\Http\Controllers;

use App\Services\Bots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Claude players in the API (#1025): an operator's key, sent as X-Bot-Key,
 * makes Claude players, gives them fresh tokens and lists them.
 */
class BotApiController extends Controller
{
    private function operator(Request $request): ?\App\User
    {
        return Bots::operatorFor($request->header(Bots::HEADER));
    }

    private function refused(): JsonResponse
    {
        return response()->json(['error' => 'Send an operator\'s key as "' . Bots::HEADER . ': <key>".'], 401);
    }

    /** POST /api/v1/bots {name}: a new Claude player, its password and token */
    public function make(Request $request): JsonResponse
    {
        if (!$operator = $this->operator($request)) {
            return $this->refused();
        }
        $result = Bots::make($operator, (string) $request->input('name', ''));
        if (!$result['ok']) {
            return response()->json(['error' => $result['error']], 422);
        }
        unset($result['ok'], $result['error']);
        return response()->json($result + ['bot' => true], 201);
    }

    /** POST /api/v1/bots/{name}/token: a fresh token for one of the operator's Claude players */
    public function token(Request $request, string $name): JsonResponse
    {
        if (!$operator = $this->operator($request)) {
            return $this->refused();
        }
        $token = Bots::token($operator, $name);
        return $token === null
            ? response()->json(['error' => 'No Claude player of yours has that name.'], 404)
            : response()->json(['name' => $name, 'token' => $token]);
    }

    /** GET /api/v1/bots: the operator's Claude players */
    public function index(Request $request): JsonResponse
    {
        if (!$operator = $this->operator($request)) {
            return $this->refused();
        }
        return response()->json(['operator' => $operator->login, 'max' => (int) config('forge.bot_max'),
            'players' => Bots::players($operator)]);
    }
}
