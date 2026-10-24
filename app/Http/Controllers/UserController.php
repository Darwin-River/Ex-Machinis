<?php

namespace App\Http\Controllers;

use App\Agent;
use App\User;
use App\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class UserController extends Controller
{

    /**
     * Sends a mail with a list of all current users
     * @param int $key validation key
     */
    public function reportCurrentUsersByMail($key)
    {
        $this->requireCronKey($key);

        $currentUsers = User::with('agents')
            ->orderBy('user_id', 'desc')
            ->get();
        try {
            Mail::send('email.users_report', ['currentUsers' => $currentUsers], function ($message)/* use ($user, $mail)*/ {
                $message->to(getenv('REPORTS_DESTINATION'))->from("notifitacions@" . config('mail.domain'), getenv("APP_NAME"))->subject("[" . getenv("APP_NAME") . "] Users Report");
            });

            return response("Notification sent");
        } catch (\Exception $e) {
            report($e);
            return response("There was an error sending the report.", 500);
        }
    }

    /**
     * Creates user and shipcraft from submitted form
     * @param $request Request object
     * @return RedirectResponse
     * @throws ValidationException
     */
    public function webRegistration(Request $request)
    {
        $this->validate(request(), [
            'email' => 'required|email|unique:users|max:255',
            'name' => 'required|unique:users',
        ]);
        $name = $request->post('name');
        $email = $request->post('email');
        User::registerUser($email, $name);

        return redirect('/message')->withSuccess('Thank you for joining ' . getenv("APP_NAME") . '! Please check your e-mail inbox to continue.');
    }

    /**
     * Displays companies search engine
     */
    public function companyIndex()
    {
        return view('user.companies');
    }

    /**
     * Used by search engine to retrieve companies (users actually)
     * @param $request Request object
     * @return JsonResponse
     */
    public function companySearch(Request $request)
    {
        $resultsPerPage = Config::get('constants.options.results_per_page');
        // Companies (#324 step 4); the JSON keeps the user_id key for the page's links
        $query = DB::table('companies')->select('companies.company_id AS user_id', 'companies.name AS name', DB::raw('DATEDIFF(NOW(),companies.created_at) AS age')
            , DB::raw('COUNT(agents.agent_id) AS agents'), DB::raw('IFNULL(companies.credits,0) AS credits'))
            ->leftJoin('agents', 'agents.company_id', '=', 'companies.company_id')->groupBy('companies.company_id');
        if ($request->get('user_id') !== null)
            $query->where('companies.company_id', intval($request->get('user_id')));
        if ($request->get('name') !== null)
            $query->where('companies.name', 'LIKE', "%" . $request->get('name') . "%");
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
     * @param int $id company id
     * @return View
     */
    public function companyView($id)
    {
        $company = Company::where('company_id', '=', $id)->firstOrFail();
        // The view shows the company's ID, name, start date and bank account
        $user = (object) ['user_id' => $company->company_id, 'name' => $company->name,
            'created_at' => $company->created_at, 'credits' => $company->credits];

        $spacecraft = Agent::with('hull')->with('location')->with('lastEVents')->where('company_id', '=', $id)->get();

        return view('user.company', compact('user', 'spacecraft'));
    }

}
