<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use Validator;
use Illuminate\Http\Request;

class SliderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sliders= Slider::query()->paginate(request()->limit);
        return response()->success($sliders, 'Sliders retrieved successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(),
            [
                "name" => 'required',
                "image" => 'required',
                "description" => 'required',
                "status" => 'required|boolean',
            ]
        );

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }

        $slider = Slider::create($request->all());
        return response()->success($slider, 'Slider created successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(Slider $slider)
    {
        return response()->success($slider, 'Slider retrieved successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Slider $slider)
    {
        $validator = Validator::make($request->all(),
            [
                "name" => 'required',
                "image" => 'required',
                "description" => 'required',
                "status" => 'required|boolean',
            ]
        );

        if ($validator->fails()) {
            return response()->error('Validation failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }

        if ($request->image) {
            $slider->image = $request->image;
            $slider->save();
        }
        $slider->update([
            "name" => $request->name,
            "description" => $request->description,
            "status" => $request->status,
        ]);

        return response()->success($slider->fresh(), 'Slider updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Slider $slider)
    {
        //
    }
}
