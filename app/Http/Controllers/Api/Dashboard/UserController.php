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
        $query = User::query()->with('role');

        if (! TenantAccess::isSuperAdmin(auth()->user()) && \Schema::hasColumn('users', 'partner_id')) {
            $query->where('partner_id', auth()->user()->partner_id);
        }
        if ($search) {
            $query = $query->where('name', 'like', '%' . $search . '%');
        }
        $users = $query->paginate((int) request()->get('limit', 10));

        return response()->success([
            'list' => $users->getCollection()->map(fn (User $user) => $this->dashboardUser($user))->values(),
            'metaData' => [
                'total' => $users->total(),
                'page' => $users->currentPage(),
                'limit' => $users->perPage(),
                'lastPage' => $users->lastPage(),
            ],
        ], 'Users retrieved successfully');
    }

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'fullName' => 'required_without:name|max:255',
            'name' => 'required_without:fullName|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'mobile' => 'nullable|string|unique:users',
            'role' => 'required_without:role_id|string',
            'role_id' => 'required_without:role|integer|exists:roles,id',
            'avatar' => 'nullable',
            'type' => 'required',
            'companyId' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }

        $roleId = $this->allowedRoleId($request->role_id ?: $this->roleIdByName($request->role));

        $user = User::create([
            'name' => $request->fullName ?: $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'role_id' => $roleId,
            'avatar' => $request->avatar,
            'type' => $request->type,
            'password' => Hash::make($request->password)
        ]);

        return response()->success($this->dashboardUser($user->fresh('role')), 'User created successfully');

    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $this->authorizePartnerAccess($user);

        return response()->success($this->dashboardUser($user->loadMissing('role')), 'User retrieved successfully');
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
                'nullable',
                'string',
                Rule::unique('users')->ignore($user->id),
            ],
            'role' => 'sometimes|string',
            'role_id' => 'sometimes|integer|exists:roles,id',
            'avatar' => 'nullable',
            'type' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }



        if ($request->avatar) {
            $user->avatar = $request->avatar;
            $user->save();
        }
        $roleId = $request->filled('role_id') || $request->filled('role')
            ? $this->allowedRoleId($request->role_id ?: $this->roleIdByName($request->role))
            : $user->role_id;

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'role_id' => $roleId,
            'type' => $request->type,
        ]);

        return response()->success($this->dashboardUser($user->fresh('role')), 'User updated successfully');
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

        abort_if(in_array($role->name, ['SUPER_ADMIN', 'ADMIN', 'Super Admin', 'Admin'], true), 403);

        return $roleId;
    }

    private function roleIdByName(string $role): int
    {
        return (int) Role::query()
            ->whereIn('name', [$role, strtoupper($role)])
            ->valueOrFail('id');
    }

    private function dashboardUser(User $user): array
    {
        $name = $user->name;
        if (is_array($name)) {
            $name = $name['en'] ?? $name['ar'] ?? reset($name) ?: null;
        }

        return [
            'id' => $user->id,
            '_id' => (string) $user->id,
            'name' => $name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'type' => $user->type,
            'companyId' => null,
            'createdAt' => optional($user->created_at)->toJSON(),
        ];
    }
}
