<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FilesUploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $file = $request->file('file');
        $fileName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $file->storeAs('guidedia-dashboard', $fileName, 's3');

        return response()->success([
            "path" => Storage::disk('s3')->url('guidedia-dashboard/' . $fileName)
        ], 'File uploaded successfully');
    }
}
