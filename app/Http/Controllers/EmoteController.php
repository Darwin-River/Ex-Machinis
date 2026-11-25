<?php

namespace App\Http\Controllers;

use App\Services\Emotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The menu bar's emote button (#1062): no account needed. The API's
 * emotes (#1068): a player's, AI players' included, and the operator's
 * reading of the log for the feedback review (DV-06).
 */
class EmoteController extends Controller
{
    /** POST /emote {emote, comment?, visitor?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'emote' => ['required', 'string', Rule::in(array_keys(Emotes::CHOICES))],
            'comment' => ['nullable', 'string', 'max:' . Emotes::COMMENT_MAX],
            'visitor' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,36}$/'],
        ]);
        $user = Auth::user();
        $player = $user && $user->login !== null ? $user : null;

        Emotes::log($data['emote'], $data['comment'] ?? null, $player, $data['visitor'] ?? null);

        return response()->json(['emote' => $data['emote'], 'emoji' => Emotes::emoji($data['emote'])]);
    }

    /** POST /api/v1/emote {emote, comment?}: a player's emote, with a token or the operator's key and X-Bot-Player */
    public function apiStore(Request $request): JsonResponse
    {
        if (!$player = \App\Services\Players::byToken($request->bearerToken())) {
            return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
        }
        // Always JSON, whatever the caller accepts
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'emote' => ['required', 'string', Rule::in(array_keys(Emotes::CHOICES))],
            'comment' => ['nullable', 'string', 'max:' . Emotes::COMMENT_MAX],
        ], ['emote.in' => 'emote must be one of: ' . implode(', ', array_keys(Emotes::CHOICES)) . '.']);
        if ($v->fails()) {
            return response()->json(['error' => $v->errors()->first(), 'choices' => array_keys(Emotes::CHOICES)], 422);
        }
        $data = $v->validated();
        Emotes::log($data['emote'], $data['comment'] ?? null, $player, null);

        return response()->json(['emote' => $data['emote'], 'emoji' => Emotes::emoji($data['emote'])], 201);
    }

    /** GET /api/v1/emotes?since=&until=&source=&limit=: the log, with the operator's key, without names */
    public function apiIndex(Request $request): JsonResponse
    {
        if (!$operator = \App\Services\Bots::operatorFor($request->header(\App\Services\Bots::HEADER))) {
            return response()->json(['error' => 'Send an operator\'s key as "' . \App\Services\Bots::HEADER . ': <key>".'], 401);
        }
        $r = Emotes::reading($operator, $request->only(['since', 'until', 'source', 'limit']));
        return isset($r['error']) ? response()->json($r, 422) : response()->json($r);
    }

    /** GET /api/v1/emotes/choices: the emotes a player may choose, key => emoji */
    public function choices(): JsonResponse
    {
        return response()->json(['choices' => array_map(fn ($c) => ['emoji' => $c[0], 'label' => $c[1]], Emotes::CHOICES)]);
    }
}
