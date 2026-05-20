<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\CloudinaryUploadService;
use Illuminate\Http\Request;
use RuntimeException;

class FilesUploadController extends Controller
{
    public function store(Request $request, CloudinaryUploadService $cloudinary)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:1000'],
        ]);

        try {
            $result = $cloudinary->upload($request->file('file')->getRealPath());
        } catch (RuntimeException $exception) {
            return response()->error($exception->getMessage(), 500);
        }

        return response()->json([
            'serverDate' => now()->toJSON(),
            'statusCode' => 200,
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
