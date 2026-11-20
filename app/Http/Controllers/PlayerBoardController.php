<?php

namespace App\Http\Controllers;

use App\Services\PlayerBoards;
use App\Services\Players;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The player boards (#640): wealth and the daily season (#775), for everyone or
 * for a class group (its members and its teacher only). Behind the new-rules
 * switch (#635).
 */
class PlayerBoardController extends Controller
{
    /** The class group the viewer may see the boards of, or null for the public's; false when not allowed */
    private static function group(?\App\User $viewer, $class)
    {
        if ($class === null || $class === '') {
            return null;
        }
        $id = (int) $class;
        $row = DB::table('class_groups')->where('id', $id)->first();
        if (!$row || !$viewer) {
            return false;
        }
        return (int) $viewer->class_group_id === $id || (int) $row->teacher_id === (int) $viewer->user_id ? $row : false;
    }

    public function show(Request $request)
    {
        $viewer = Auth::user();
        $viewer = $viewer && $viewer->login !== null ? $viewer : null;
        // A pupil sees their class's boards unless they ask for the public's
        $class = $request->query('class', $viewer && $viewer->class_group_id ? (string) $viewer->class_group_id : null);
        $group = self::group($viewer, $class);
        abort_if($group === false, 404);
        $boards = PlayerBoards::boards($group ? (int) $group->id : null);
        return view('world.players', ['boards' => $boards, 'group' => $group, 'me' => $viewer?->login]);
    }

    /** A player's public page (#905), or 404 for one the public boards don't list */
    public function player(string $name)
    {
        $p = PlayerBoards::profile($name);
        abort_if($p === null, 404);
        return view('world.player', ['p' => $p]);
    }

    /** GET /api/v1/players/{name} (#905): what the page shows */
    public function apiPlayer(string $name): JsonResponse
    {
        $p = PlayerBoards::profile($name);
        return $p ? response()->json($p) : response()->json(['error' => 'No such player on the public boards.'], 404);
    }

    /** GET /api/v1/players[?class=ID]: the boards (a class's need its member's or teacher's token) */
    public function api(Request $request): JsonResponse
    {
        $viewer = Players::byToken($request->bearerToken());
        $group = self::group($viewer, $request->query('class'));
        if ($group === false) {
            return response()->json(['error' => 'No such class group, or it isn\'t yours.'], 404);
        }
        return response()->json(['class_group' => $group ? ['id' => (int) $group->id, 'name' => $group->name] : null]
            + PlayerBoards::boards($group ? (int) $group->id : null));
    }

    /** GET /api/v1/account/digest: what happened since the token's account last looked */
    public function digest(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
        }
        return response()->json(PlayerBoards::digest($user, $request->query('peek') === null));
    }

    /** GET /api/v1/account/records: the account's personal records (#775) */
    public function records(Request $request): JsonResponse
    {
        $user = Players::byToken($request->bearerToken());
        if (!$user) {
            return response()->json(['error' => 'Send your token as "Authorization: Bearer <token>".'], 401);
        }
        return response()->json(PlayerBoards::records($user));
    }
}
