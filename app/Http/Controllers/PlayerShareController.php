<?php

namespace App\Http\Controllers;

use App\Services\Players;
use App\Services\PlayerShares;
use App\Services\TradeScripts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        if ($e = self::notWhole($request)) {
            return back()->with('error', $e);
        }
        $r = PlayerShares::sellToBank($user, $id, (int) $request->input('units'));
        return $this->back($r, $r['ok'] ? 'The bank has your ' . $r['units'] . ' shares: it pays ' . $r['due']
            . ', at the lower of ' . number_format($r['price_now']) . ' a share and their cash-out value then.' : '');
    }

    public function buyFromBank(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        if ($e = self::notWhole($request)) {
            return back()->with('error', $e);
        }
        $r = PlayerShares::buyFromBank($user, $id, (int) $request->input('units'));
        return $this->back($r, $r['ok'] ? 'You bought ' . $r['units'] . ' shares from the bank for ' . number_format($r['cost']) . ' credits.' : '');
    }

    /** The Trading desk (#779): ?company=N puts that company on the ladder */
    public function desk(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $company = $request->filled('company') ? (int) $request->input('company') : null;
        return view('world.trade', ['desk' => \App\Services\TradingDesk::build($user, $company)]);
    }

    /** Its owner offers 10 new shares of a matured company (#780) */
    public function offer(int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $r = PlayerShares::offer($user, $id);
        return $this->back($r, $r['ok'] ? 'It offers ' . $r['units'] . ' new shares at its cash-out value a share ('
            . number_format($r['price']) . ' now): what they fetch goes to the company.' : '');
    }

    /** Its owner withdraws the open offering (#780) */
    public function withdrawOffer(int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $ok = PlayerShares::withdraw($user, $id);
        return $this->back($ok ? ['ok' => true] : ['ok' => false, 'error' => 'There is no offering of yours to withdraw.'],
            'The rest of the offering is withdrawn; the shares already sold stay sold.');
    }

    /** Buys new shares from a company's offering (#780) */
    public function buyOffered(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        if ($e = self::notWhole($request)) {
            return back()->with('error', $e);
        }
        $r = PlayerShares::buyOffered($user, $id, (int) $request->input('units'));
        return $this->back($r, $r['ok'] ? 'You bought ' . $r['units'] . ' new shares for ' . number_format($r['cost'])
            . ' credits; they went to the company, which now has ' . $r['count'] . ' shares.' : '');
    }

    public function order(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        // A pegged order from the company page's form (#766)
        $peg = in_array($request->input('peg'), PlayerShares::PEGS, true)
            ? ['ref' => $request->input('peg'), 'offset_bp' => (int) round((float) $request->input('offset_percent', 0) * 100)] : null;
        if ($peg === null && ($e = self::notWhole($request))) {
            return back()->with('error', $e);
        }
        $r = PlayerShares::order($user, $id, (string) $request->input('side'), (int) $request->input('units'), (int) $request->input('price'), $peg);
        return $this->back($r, $r['ok'] ? $r['traded'] . ' shares traded now' . ($r['standing'] ? ', and an order for '
            . $r['standing'] . ' more stands.' : '.') : '');
    }

    /** The order ticket's quote (#845), for the page's own script: the session's player, or a visitor's */
    public function quote(Request $request, int $id): JsonResponse
    {
        $limit = $request->filled('limit') ? self::whole($request, 'limit') : null;
        $q = \App\Services\ShareTicket::quote($this->webPlayer(), $id, (string) $request->input('side'), self::whole($request, 'units'), $limit,
            $request->boolean('book'));
        return $this->json($q);
    }

    /** Buy now / Sell now from the ticket (#845) */
    public function now(Request $request, int $id)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        if ($e = self::notWhole($request)) {
            return back()->with('error', $e);
        }
        $limit = $request->filled('limit') ? self::whole($request, 'limit') : null;
        $r = \App\Services\ShareTicket::now($user, $id, (string) $request->input('side'), self::whole($request, 'units'), $limit);
        return $this->back($r, $r['ok'] ? self::nowWords($r) : '');
    }

    /** What Buy now / Sell now did, in words */
    private static function nowWords(array $r): string
    {
        $bank = collect($r['legs'])->firstWhere('route', 'bank');
        return ($r['side'] === 'buy' ? 'You bought ' : 'You sold ') . $r['units'] . ' shares for ' . number_format($r['credits']) . ' credits'
            . ($r['side'] === 'sell' && $bank ? ' (the bank\'s part is paid ' . ($bank['due'] ?? 'later') . ')' : '') . '.'
            . ($r['short'] > 0 ? ' ' . $r['short'] . ' more couldn\'t be ' . ($r['side'] === 'buy' ? 'bought' : 'sold') . ' now.' : '');
    }

    /** Moves a standing order to a new price, from the desk (#845) */
    public function move(Request $request, int $order)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        if ($e = self::notWhole($request)) {
            return back()->with('error', $e);
        }
        $r = PlayerShares::moveOrder($user, $order, self::whole($request, 'price'));
        return $this->back($r, $r['ok'] ? 'Your order now ' . ($r['standing'] ? 'stands at ' . number_format($r['price']) : 'is gone')
            . ($r['traded'] ? ': ' . $r['traded'] . ' shares traded at once.' : '.') : '');
    }

    public function cancel(int $order)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        return PlayerShares::cancel($user, $order) ? back()->withSuccess('Your order is cancelled.')
            : back()->with('error', 'You have no such order.');
    }

    // The API, with an account's token

    private function apiPlayer(Request $request): ?\App\User
    {
        return Players::byToken($request->bearerToken());
    }

    // The account trading script (#768)

    public function saveTradeScript(Request $request)
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $r = TradeScripts::save($user, (string) $request->input('code'), $request->boolean('enabled'));
        return $this->back($r, ($r['removed'] ?? false) ? 'Your trading script is removed.'
            : ($request->boolean('enabled') ? 'Your trading script is saved, and runs every few real minutes.' : 'Your trading script is saved, switched off.'));
    }

    public function runTradeScript()
    {
        if (!$user = $this->webPlayer()) {
            return redirect('/login')->with('info', 'Log in first: that page is for your account.');
        }
        $out = TradeScripts::run($user);
        return $out === null ? back()->with('error', 'You have no trading script to run.')
            : back()->withSuccess('Your trading script ran: ' . implode('; ', $out ?: ['it did nothing']) . '.');
    }

    public function apiTradeScript(Request $request): JsonResponse
    {
        $user = $this->apiPlayer($request);
        return $user ? response()->json(TradeScripts::of($user) ?? ['code' => null, 'enabled' => false, 'runs' => 0, 'last_run' => null, 'log' => []])
            : $this->unauthorized();
    }

    public function apiSaveTradeScript(Request $request): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        return $this->json(TradeScripts::save($user, (string) $request->input('code'), $request->boolean('enabled')));
    }

    public function apiRunTradeScript(Request $request): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        $out = TradeScripts::run($user);
        return $out === null ? response()->json(['error' => 'You have no trading script to run.'], 404) : response()->json(['lines' => $out]);
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
            return response()->json(['error' => DB::table('companies')->where('company_id', $id)->whereNotNull('eliminated_at')->exists()
                ? 'That company has ended: its shares are worth nothing and no longer trade.' : 'There is no such matured company.'], 404);
        }
        return response()->json(['company' => $id] + PlayerShares::prices($id) + ['count' => PlayerShares::count($id),
            'offering' => PlayerShares::offering($id), 'holders' => PlayerShares::holders($id), 'book' => PlayerShares::book($id),
            'trades' => PlayerShares::trades($id, 20)]);
    }

    /** GET /api/v1/account/shares */
    public function apiMine(Request $request): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        // Each holding with its cost basis and gain, as the Trading desk works them out (#495)
        $mine = PlayerShares::of($user);
        $gains = collect(\App\Services\TradingDesk::gains((int) $user->user_id, $mine['holdings']))->keyBy('company_id');
        $mine['holdings'] = array_map(fn ($h) => $h + \Illuminate\Support\Arr::only($gains[$h['company_id']] ?? [],
            ['cost', 'founder_units', 'dividends', 'gain', 'gain_percent'])
            + ['average_price' => ($g = $gains[$h['company_id']] ?? null) && $h['units'] - $g['founder_units'] > 0
                ? (int) round($g['cost'] / ($h['units'] - $g['founder_units'])) : null], $mine['holdings']);
        return response()->json($mine);
    }

    public function apiSellToBank(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if ($user && ($e = self::notWhole($request))) {
            return response()->json(['error' => $e], 422);
        }
        return $user ? $this->json(PlayerShares::sellToBank($user, $id, self::whole($request, 'units'))) : $this->unauthorized();
    }

    public function apiBuyFromBank(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if ($user && ($e = self::notWhole($request))) {
            return response()->json(['error' => $e], 422);
        }
        return $user ? $this->json(PlayerShares::buyFromBank($user, $id, self::whole($request, 'units'))) : $this->unauthorized();
    }

    /** POST /api/v1/player-shares/{id}/offering: its owner offers 10 new shares (#780) */
    public function apiOffer(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        return $user ? $this->json(PlayerShares::offer($user, $id)) : $this->unauthorized();
    }

    /** POST /api/v1/player-shares/{id}/offering/withdraw (#780) */
    public function apiWithdrawOffer(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        return PlayerShares::withdraw($user, $id) ? response()->json(['ok' => true])
            : response()->json(['error' => 'There is no offering of yours to withdraw.'], 404);
    }

    /** POST /api/v1/player-shares/{id}/offering/buy {units} (#780) */
    public function apiBuyOffered(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if ($user && ($e = self::notWhole($request))) {
            return response()->json(['error' => $e], 422);
        }
        return $user ? $this->json(PlayerShares::buyOffered($user, $id, self::whole($request, 'units'))) : $this->unauthorized();
    }

    public function apiOrder(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        // A pegged order (#766): peg (buys_at or sells_at) and offset_percent in place of price
        if ($request->filled('peg')) {
            $offset = $request->input('offset_percent', 0);
            if (!is_numeric($offset) || self::whole($request, 'units') < 1) {
                return response()->json(['error' => 'A pegged order is a whole number of shares, at least 1, with peg (buys_at or sells_at) and offset_percent, a number.'], 422);
            }
            return $this->json(PlayerShares::order($user, $id, (string) $request->input('side'), self::whole($request, 'units'), 0,
                ['ref' => (string) $request->input('peg'), 'offset_bp' => (int) round((float) $offset * 100)]));
        }
        if ($e = self::notWhole($request)) {
            return response()->json(['error' => $e], 422);
        }
        // Whole shares at a whole number of credits (#674: 70.5 was stored as 70)
        if (self::whole($request, 'units') < 1 || self::whole($request, 'price') < 1) {
            return response()->json(['error' => 'An order is a whole number of shares, at least 1, at a whole number of credits, at least 1.'], 422);
        }
        return $this->json(PlayerShares::order($user, $id, (string) $request->input('side'),
            self::whole($request, 'units'), self::whole($request, 'price')));
    }

    /**
     * Why the request's units or price isn't a whole number, or null (#715:
     * 1.5 shares answered "Buy at least 1 whole share.")
     */
    private static function notWhole(Request $request): ?string
    {
        foreach (['units' => 'Shares are whole', 'price' => 'Prices are whole credits'] as $key => $what) {
            $v = $request->input($key);
            if ($v === null || $v === '' || is_int($v) || !is_numeric($v)) {
                continue;
            }
            if ((float) $v != floor((float) $v)) {
                return $what . ': ' . (is_string($v) ? trim($v) : rtrim(rtrim(sprintf('%.6F', $v), '0'), '.'))
                    . " isn't a whole number.";
            }
        }
        return null;
    }

    /** A whole number from the request, or 0 when it isn't one (0.5, "abc") */
    private static function whole(Request $request, string $key): int
    {
        $v = $request->input($key);
        if (is_int($v)) {
            return $v;
        }
        // A whole number sent as a JSON float, 1.0 (#692), is a whole number
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < PHP_INT_MAX) {
            return (int) $v;
        }
        return is_string($v) && preg_match('/^-?\d+$/', trim($v)) ? (int) $v : 0;
    }

    /** GET /player-shares/{id}/quote?side=&units=&limit= (#845): the best route for N shares now, without trading */
    public function apiQuote(Request $request, int $id): JsonResponse
    {
        $limit = $request->filled('limit') ? self::whole($request, 'limit') : null;
        return $this->json(\App\Services\ShareTicket::quote($this->apiPlayer($request), $id, (string) $request->input('side'),
            self::whole($request, 'units'), $limit, $request->boolean('book')));
    }

    /** POST /player-shares/{id}/now (#845): buy or sell N shares now by the best route, all or none */
    public function apiNow(Request $request, int $id): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        if ($e = self::notWhole($request)) {
            return response()->json(['error' => $e], 422);
        }
        $limit = $request->filled('limit') ? self::whole($request, 'limit') : null;
        return $this->json(\App\Services\ShareTicket::now($user, $id, (string) $request->input('side'), self::whole($request, 'units'), $limit));
    }

    /** POST /player-shares/orders/{order}/move (#845): a standing order to a new price, keeping its id */
    public function apiMove(Request $request, int $order): JsonResponse
    {
        $user = $this->apiPlayer($request);
        if (!$user) {
            return $this->unauthorized();
        }
        if ($e = self::notWhole($request)) {
            return response()->json(['error' => $e], 422);
        }
        return $this->json(PlayerShares::moveOrder($user, $order, self::whole($request, 'price')));
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
