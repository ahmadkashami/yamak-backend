<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Variable;
use Illuminate\Http\Request;
use Validator;
use Illuminate\Validation\Rule;

class VariableController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $variables = Variable::query()->paginate(request()->limit);
        return response()->success($variables);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug_name' => 'required|unique:variables',
            'status' => 'nullable',
            'display_name' => 'required',
            'title' => 'nullable',
            'text' => 'nullable',
            'image' => 'nullable|string|max:250',
            'video' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }
        $variables = Variable::create([
            'slug_name' => $request->slug_name,
            'status' => $request->status,
            'display_name' => $request->display_name,
            'title' => $request->title,
            'text' => $request->text,
            'image' => $request->image,
            'video' => $request->video
        ]);

        return response()->success($variables, 'Variable created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Variable $variable)
    {
        return response()->success($variable, 'Variable retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Variable $variable)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable',
            'display_name' => 'required',
            'text' => 'nullable',
            'title' => 'nullable',
            'image' => 'nullable|string|max:250',
            'video' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }
        $variables = $variable->update([
            'status' => $request->status,
            'display_name' => $request->display_name,
            'text' => $request->text,
            'title' => $request->title,
            'image' => $request->image,
            'video' => $request->video
        ]);

        return response()->success($variable->fresh(), 'Variable updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Variable $variable)
    {
        //
    }
}
