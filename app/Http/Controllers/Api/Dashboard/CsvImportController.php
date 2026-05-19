<?php

namespace App\Http\Controllers\Api\Dashboard;


use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\CountriesImport;
use App\Http\Controllers\Controller;
use Validator;

class CsvImportController extends Controller
{
    public function import(Request $request)
    {

         $validator = Validator::make($request->all(), [
                    'file' => 'required|mimes:csv,txt|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->error('validation_failed', 422, $validator->errors(), 'VALIDATION_ERROR');
        }

        // Handle the file upload directly from the request
        $file = $request->file('file');

        // Use Laravel Excel to handle the import
        Excel::import(new CountriesImport, $file);

        // Return a response
        return response()->success(null, 'data_imported_successfully');
    }
}
