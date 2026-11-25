<?php

namespace App\Http\Controllers;

use App\Services\Emotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** The menu bar's emote button (#1062): no account needed */
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
}
