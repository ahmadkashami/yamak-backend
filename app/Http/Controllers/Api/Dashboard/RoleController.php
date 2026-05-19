<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Validator;


class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $search = request()->search;
        $query = Role::query();
        if (! TenantAccess::isSuperAdmin(auth()->user())) {
            $query->whereIn('name', ['Partner Admin', 'Partner Manager', 'Partner Editor', 'Partner Viewer']);
        }
        if ($search) {
            $query = $query->where('display_name', 'like', '%' . $search . '%');
        }
        $roles = $query->paginate(request()->limit);
        return response()->success($roles, 'Roles retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:roles',
            'display_name' => 'required|unique:roles'
        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }
        $role = Role::create([
            'name' => $request->name,
            'display_name' => $request->display_name,
        ]);
        return response()->success($role, 'Role created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role)
    {
        return response()->success($role, 'Role retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                Rule::unique('roles')->ignore($id),
            ],
            'display_name' => [
                'required',
                Rule::unique('roles')->ignore($id),
            ],

        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }

        $role->update([
            'name' => $request->name,
            'display_name' => $request->display_name,
        ]);

        return response()->success($role->fresh(), 'Role updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        //
    }
}
