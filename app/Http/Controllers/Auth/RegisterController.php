<?php

namespace App\Http\Controllers\Auth;

use App\Agent;
use App\Company;
use App\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'company_name' => 'required|string|min:4|max:255|unique:companies,name',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array $data
     * @return \App\User
     */
    protected function create(array $data)
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);
        $company = new Company();
        $company->user_id = $user->id;
        $company->name = $data['company_name'];
        $company->save();
        //create initial drones:
        for ($i = 0; $i < Company::INITIAL_AGENTS; $i++) {
            $agent = new Agent();
            $agent->assignRandomName();
            $agent->company_id = $company->id;
            //to be changed
            $agent->coord_x = 0;
            $agent->coord_y = 0;
            $agent->coord_z = 0;
            $agent->save();
        }

        return $user;
    }
}
