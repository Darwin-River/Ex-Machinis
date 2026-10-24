<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Invite;
use App\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Opening an account on the website (#324 step 6). With email switched off
 * this is how players join: an account has no company or drones until the
 * player releases a script. In an invite-only world it needs an unused
 * invitation code. With email on, players join by email (web-register)
 * and the sign-up page shows that form instead.
 */
class RegisterController extends Controller
{
    use RegistersUsers;

    protected $redirectTo = '/release';

    public function __construct()
    {
        $this->middleware('guest');
    }

    public function showRegistrationForm()
    {
        return view(config('forge.email') ? 'auth.register' : 'auth.forge-register');
    }

    protected function validator(array $data)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ];
        $validator = Validator::make($data, $rules);

        if (config('forge.invite_only')) {
            $validator->after(function ($validator) use ($data) {
                if (!Invite::findUnused($data['invite_code'] ?? null)) {
                    $validator->errors()->add('invite_code', 'That invitation code is not valid or has been used.');
                }
            });
        }

        return $validator;
    }

    protected function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => bcrypt($data['password']),
            ]);

            if (config('forge.invite_only')) {
                $invite = Invite::findUnused($data['invite_code'] ?? null);
                abort_if($invite === null, 422, 'That invitation code is not valid or has been used.');
                $invite->useFor($user);
            }

            return $user;
        });
    }
}
