<?php

namespace App\Http\Controllers;

use App\Services\Loans;
use App\Services\Players;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Loans (#928): the board of public companies' loan requests, backing them
 * from the website or the API, and a player's own loans.
 */
class LoanController extends Controller
{
    private function webPlayer(): ?\App\User
    {
        $user = Auth::user();
        return $user && $user->login !== null ? $user : null;
    }

    /** GET /market/loans */
    public function index()
    {
        $requests = Loans::requests();
        $user = $this->webPlayer();
        $mine = $user ? Loans::ofUser((int) $user->user_id) : null;
        $credits = $user ? (int) \Illuminate\Support\Facades\DB::table('users')->where('user_id', $user->user_id)->value('credits') : null;
        $min = Loans::minCredits();
        return view('world.loans', compact('requests', 'mine', 'credits', 'min'));
    }

    /** POST /loans/{id}/back {credits} */
    public function back(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $r = Loans::back($user, $id, (int) $request->input('credits'));
        return $r['ok'] ? back()->withSuccess('You lent ' . number_format($r['lent']) . ' credits: it owes you '
            . number_format($r['owed']) . ' on ' . $r['due'] . '.') : back()->with('error', $r['error']);
    }

    /** GET /api/v1/loans: the open loan requests */
    public function apiIndex(): JsonResponse
    {
        return response()->json(['min_credits' => Loans::minCredits(), 'requests' => Loans::requests()]);
    }

    /** POST /api/v1/loans/{id}/back {credits} */
    public function apiBack(Request $request, int $id): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return response()->json(['error' => 'Send your API token as a Bearer token.'], 401);
        }
        $r = Loans::back($user, $id, (int) $request->input('credits'));
        return $r['ok'] ? response()->json($r) : response()->json(['error' => $r['error']], $r['status']);
    }

    /** GET /api/v1/account/loans: the player's loans */
    public function apiMine(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return response()->json(['error' => 'Send your API token as a Bearer token.'], 401);
        }
        return response()->json(Loans::ofUser((int) $user->user_id));
    }
}
