<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\CloudinaryUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class FilesUploadController extends Controller
{
    private const VALIDATION_MESSAGE = 'size limit : 1mb , file type : jpg|jpeg|png ';

    public function store(Request $request, CloudinaryUploadService $cloudinary)
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->error(self::VALIDATION_MESSAGE, 400);
        }

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
