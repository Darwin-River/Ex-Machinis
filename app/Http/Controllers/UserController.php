<?php

namespace App\Http\Controllers;

use App\Agent;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class UserController extends Controller
{

    /**
     * Sends a mail with a list of all current users
     * @param int $key validation key
     */
    public function reportCurrentUsersByMail($key)
    {

        $currentUsers = User::with('agents')
            ->orderBy('agent_id', 'desc')
            ->get();
        try {
            Mail::send('email.users_report', ['currentUsers' => $currentUsers], function ($message)/* use ($user, $mail)*/ {
                $message->to(getenv('REPORTS_DESTINATION'))->from("notifitacions@" . getenv("MAIL_HOST"), getenv("APP_NAME"))->subject("[" . getenv("APP_NAME") . "] Users Report");
            });

            echo "Notification sent";
        } catch (\Exception $e) {
            echo "There was an error:" . $e->getMessage();
        }
    }

    /**
     * Creates user and shipcraft from submitted form
     * @param $request Request object
     */
    public function webRegistration(Request $request)
    {
        $name = $request->post('name');
        $email = $request->post('email');
        User::registerUser($email, $name);
    }

    /**
     * Displays companies search engine
     */
    public function companyIndex()
    {
        return view('user.companies', compact('protocols'));
    }

    /**
     * Used by search engine to retrieve companies (users actually)
     * @param $request Request object
     * @return JsonResponse
     */
    public function companySearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');
        $query = DB::table('users')->select('users.user_id AS user_id', 'users.name AS name', DB::raw('DATEDIFF(NOW(),users.created_at) AS age')
            , DB::raw('COUNT(agents.agent_id) AS agents'), DB::raw('IFNULL(users.credits,0) AS credits'))
            ->leftJoin('agents', 'agents.user_id', '=', 'users.user_id')->groupBy('users.user_id');
        if ($request->get('user_id') !== null)
            $query->where('users.user_id', intval($request->get('user_id')));
        if ($request->get('name') !== null)
            $query->where('users.name', 'LIKE', "%" . $request->get('name') . "%");
        if ($request->get('sort')) {
            $sortParts = explode('|', $request->get('sort'));
            $query->orderBy($sortParts[0], $sortParts[1]);
        }
        $spaceObjects = $query->paginate($resultsPerPage);
        // var_dump($spaceObjects);exit;

        return response()->json($spaceObjects, 200);
    }

    /**
     * Displays single company
     * @param int $id user id
     * @return View
     */
    public function companyView($id)
    {
        $user = User::where('user_id', '=', $id)->first();

        $spacecraft = Agent::with('hull')->with('location')->with('lastEVents')->where('user_id', '=', $id)->get();

        return view('user.company', compact('user', 'spacecraft'));
    }

}
