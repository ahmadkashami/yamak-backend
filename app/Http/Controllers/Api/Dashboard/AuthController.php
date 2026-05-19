<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\RegisterUserRequest;
use App\Http\Requests\Dashboard\UpdateProfileRequest;
use App\Services\UserProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(private readonly UserProfileService $profiles)
    {
    }

    public function register(RegisterUserRequest $request)
    {
        $user = $this->profiles->register($request);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->success([
            'user' => userTransform($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 'registered_successfully');
    }


    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->error('invalid_credentials', 401, null, 'UNAUTHORIZED');
        }
        $user = \App\Models\User::with(['role', 'partner', 'city', 'country'])->where('email', $request['email'])->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->success([
            'user' => userTransform($user),
                'access_token' => $token,
                'token_type' => 'Bearer',
        ], 'login_successful');
    }


    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $this->profiles->update($request->user(), $request->validated());

        return response()->success(userTransform($user), 'profile_updated_successfully');
    }


    public function logout(): \Illuminate\Http\JsonResponse
    {
        auth()->user()->tokens()->delete();

        return response()->success(null, 'logout_successful');
    }
}
