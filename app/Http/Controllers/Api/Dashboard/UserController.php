<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Validator;

class UserController extends Controller
{

    public function index()
    {
        $search = request()->search;
        $query = User::query();

        if (! TenantAccess::isSuperAdmin(auth()->user())) {
            $query->where('partner_id', auth()->user()->partner_id);
        }
        if ($search) {
            $query = $query->where('name', 'like', '%' . $search . '%');
        }
        $users = $query->paginate(request()->limit);
        return response()->success($users, 'Users retrieved successfully');
    }

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'mobile' => 'required|numeric|unique:users',
            'role_id' => 'required|integer|exists:roles,id',
            'avatar' => 'required',
            'type' => 'required',
            'partner_id' => 'nullable|integer|exists:partners,id',
            'country_id' => 'required|exists:countries,id',
            'city_id' => 'required|exists:cities,id',
        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }

        $partnerId = TenantAccess::partnerIdForCreate(auth()->user(), $request->partner_id);
        $roleId = $this->allowedRoleId($request->role_id);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'role_id' => $roleId,
            'avatar' => $request->avatar,
            'type' => $request->type,
            'partner_id' => $partnerId,
            'country_id' => $request->country_id,
            'city_id' => $request->city_id,
            'password' => Hash::make($request->password)
        ]);

        return response()->success($user, 'User created successfully');

    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $this->authorizePartnerAccess($user);

        return response()->success(userTransform($user), 'User retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $this->authorizePartnerAccess($user);

        $validator = Validator::make($request->all(), [
            'name' => "required",
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'mobile' => [
                'required',
                'numeric',
                Rule::unique('users')->ignore($user->id),
            ],
            'role_id' => 'required|integer|exists:roles,id',
            'avatar' => 'nullable',
            'type' => 'required',
            'partner_id' => 'nullable|integer|exists:partners,id',
            'country_id' => 'required',
            'city_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }



        if ($request->avatar) {
            $user->avatar = $request->avatar;
            $user->save();
        }
        $roleId = $this->allowedRoleId($request->role_id);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'role_id' => $roleId,
            'type' => $request->type,
            'country_id' => $request->country_id,
            'city_id' => $request->city_id,
        ]);

        return response()->success($user->fresh(), 'User updated successfully');
    }

    private function authorizePartnerAccess(User $user): void
    {
        if (TenantAccess::isSuperAdmin(auth()->user())) {
            return;
        }

        abort_unless((int) $user->partner_id === (int) auth()->user()->partner_id, 403);
    }

    private function allowedRoleId($roleId)
    {
        if (TenantAccess::isSuperAdmin(auth()->user())) {
            return $roleId;
        }

        $role = Role::query()->findOrFail($roleId);

        abort_if(in_array($role->name, ['Super Admin', 'Admin'], true), 403);

        return $roleId;
    }
}
