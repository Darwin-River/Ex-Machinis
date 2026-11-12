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
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class UserController extends Controller
{

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

        // The stock market (#471)
        $shares = \App\Services\Shares::summary((int) $company->company_id);
        $shares['compute'] = \App\Services\ProcessorTax::of((int) $company->company_id);
        $shares['health'] = \App\Services\CompanyHealth::of((int) $company->company_id)
            + ['drones' => \App\Services\CompanyHealth::drones((int) $company->company_id)];
        // Its leaderboard place among companies its age or younger (#548),
        // and its net wealth, the game's score, at the top of the page (#591)
        $board = \App\Services\Leaderboard::wealth();
        $rank = \App\Services\Leaderboard::peerRank((int) $company->company_id, $board);
        $stats = ['wealth' => $board[$company->company_id]['wealth'] ?? null,
            'age' => \App\Services\Leaderboard::age(\Illuminate\Support\Facades\DB::table('companies')
                ->where('company_id', $company->company_id)->value('founded_t'), \App\Services\World::gameNow()),
            'working' => $spacecraft->whereNull('wrecked_at')->count(), 'drones' => $spacecraft->count()];
        // Code pushes (#553)
        $pushes = \App\Services\CodePush::summary(\Illuminate\Support\Facades\DB::table('companies')
            ->where('company_id', $company->company_id)->first());

        return view('user.company', compact('user', 'spacecraft', 'shares', 'rank', 'pushes', 'stats'));
    }

    /** Pushes new code to a company's working drones, with its password (#553) */
    public function push(\Illuminate\Http\Request $request, $id)
    {
        $request->validate(['password' => 'required|string|max:200',
            'script' => 'required|string|max:' . config('forge.max_script_length')]);
        $script = str_replace("\r\n", "\n", (string) $request->input('script'));
        $result = \App\Services\CodePush::push((int) $id, (string) $request->input('password'), $script);
        if (!$result['ok']) {
            return back()->withErrors(['push' => $result['error']])->withInput($request->except('password'));
        }
        return redirect('/companies/' . (int) $id . '#pushes')->withSuccess('Push ' . $result['number'] . ' is on its way to '
            . count($result['drones']) . ' drone(s) at the speed of light, for ' . number_format($result['fee'])
            . ' credits. Each restarts its launched function on the new code when it arrives.');
    }

}
