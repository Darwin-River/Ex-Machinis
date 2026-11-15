<?php

namespace App\Http\Controllers;

use App\Services\Players;
use App\Services\PlayerShares;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Players' share trading (#638), on the website and in the API: sell to the
 * bank, buy from it, post standing orders and cancel them. Behind the
 * new-rules switch (#635).
 */
class PlayerShareController extends Controller
{
    private function webPlayer(): ?\App\User
    {
        $user = Auth::user();
        return $user && $user->login !== null ? $user : null;
    }

    private function back(array $result, string $success)
    {
        return $result['ok'] ? back()->withSuccess($success) : back()->with('error', $result['error']);
    }

    public function sellToBank(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login');
        }
        $r = PlayerShares::sellToBank($user, $id, (int) $request->input('units'));
        return $this->back($r, $r['ok'] ? 'The bank has your ' . $r['units'] . ' shares: it pays ' . $r['due']
            . ', at the lower of ' . number_format($r['price_now']) . ' a share and their cash-out value then.' : '');
    }

    public function buyFromBank(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login');
        }
        $r = PlayerShares::buyFromBank($user, $id, (int) $request->input('units'));
        return $this->back($r, $r['ok'] ? 'You bought ' . $r['units'] . ' shares from the bank for ' . number_format($r['cost']) . ' credits.' : '');
    }

    public function order(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login');
        }
        $r = PlayerShares::order($user, $id, (string) $request->input('side'), (int) $request->input('units'), (int) $request->input('price'));
        return $this->back($r, $r['ok'] ? $r['traded'] . ' shares traded now' . ($r['standing'] ? ', and an order for '
            . $r['standing'] . ' more stands.' : '.') : '');
    }

    public function cancel(int $order)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login');
        }
        return PlayerShares::cancel($user, $order) ? back()->withSuccess('Your order is cancelled.')
            : back()->with('error', 'You have no such order.');
    }

    // The API, with an account's token

    private function apiPlayer(Request $request): ?\App\User
    {
        return Players::byToken($request->bearerToken());
    }

    private function json(array $r): JsonResponse
    {
        if (!$r['ok']) {
            return response()->json(['error' => $r['error']], $r['status']);
        }
        unset($r['ok']);
        return response()->json($r);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
    }

    /** GET /api/v1/player-shares/{id}: the bank's prices, the range, and the book */
    public function apiCompany(int $id): JsonResponse
    {
        // Only a matured company's shares trade: the API agrees with itself (#652)
        if (!PlayerShares::tradable($id)) {
            return response()->json(['error' => 'There is no such matured company.'], 404);
        }
        return response()->json(['company' => $id] + PlayerShares::prices($id) + ['book' => PlayerShares::book($id)]);
    }

    /** GET /api/v1/account/shares */
    public function apiMine(Request $request): JsonResponse
    {
        $user = $this->apiPlayer($request);
        return $user ? response()->json(PlayerShares::of($user)) : $this->unauthorized();
    }

    public function apiSellToBank(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        return $user ? $this->json(PlayerShares::sellToBank($user, $id, self::whole($request, 'units'))) : $this->unauthorized();
    }

    public function apiBuyFromBank(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        return $user ? $this->json(PlayerShares::buyFromBank($user, $id, self::whole($request, 'units'))) : $this->unauthorized();
    }

    public function apiOrder(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        // Whole shares at a whole number of credits (#674: 70.5 was stored as 70)
        if (self::whole($request, 'units') < 1 || self::whole($request, 'price') < 1) {
            return response()->json(['error' => 'An order is a whole number of shares, at least 1, at a whole number of credits, at least 1.'], 422);
        }
        return $this->json(PlayerShares::order($user, $id, (string) $request->input('side'),
            self::whole($request, 'units'), self::whole($request, 'price')));
    }

    /** A whole number from the request, or 0 when it isn't one (0.5, "abc") */
    private static function whole(Request $request, string $key): int
    {
        $v = $request->input($key);
        if (is_int($v)) {
            return $v;
        }
        return is_string($v) && preg_match('/^-?\d+$/', trim($v)) ? (int) $v : 0;
    }

    public function apiCancel(Request $request, int $order): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        return PlayerShares::cancel($user, $order) ? response()->json(['cancelled' => $order])
            : response()->json(['error' => 'You have no such order.'], 404);
    }
}
