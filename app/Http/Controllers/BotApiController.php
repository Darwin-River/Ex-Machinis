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
        return response()->json($result, 201);
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

    /** GET /api/v1/bots/{name}: one of the operator's Claude players, with its profile and notes (DV-04) */
    public function show(Request $request, string $name): JsonResponse
    {
        if (!$operator = $this->operator($request)) {
            return $this->refused();
        }
        $one = Bots::one($operator, $name);
        return $one === null ? response()->json(['error' => 'No Claude player of yours has that name.'], 404) : response()->json($one);
    }

    /** PUT /api/v1/bots/{name} {profile?, notes?}: saves its profile and notes */
    public function update(Request $request, string $name): JsonResponse
    {
        if (!$operator = $this->operator($request)) {
            return $this->refused();
        }
        $text = fn ($k) => $request->has($k) ? (string) $request->input($k) : null;
        $r = Bots::save($operator, $name, $text('profile'), $text('notes'));
        return $r['ok'] ? response()->json(Bots::one($operator, $name))
            : response()->json(['error' => $r['error']], str_starts_with($r['error'], 'No ') ? 404 : 422);
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
