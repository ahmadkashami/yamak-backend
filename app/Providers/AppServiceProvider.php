<?php

namespace App\Providers;

use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        
        /**
         * Standard success response macro
         * Format: { success: true, message: string, data: mixed, errors: null }
         */
        Response::macro('success', function($data = null, $message = null) {
            // Normalize data: ensure arrays are arrays, not null
            $normalizedData = $data;
            
            if (is_null($normalizedData)) {
                $normalizedData = null;
            } elseif (is_array($normalizedData)) {
                // Ensure empty arrays stay as arrays, not null
                $normalizedData = empty($normalizedData) ? [] : $normalizedData;
            } elseif (is_object($normalizedData)) {
                // Handle Laravel collections and models
                if (method_exists($normalizedData, 'toArray')) {
                    $normalizedData = $normalizedData->toArray();
                    
                    // Ensure pagination data arrays are arrays, not null
                    if (isset($normalizedData['data']) && is_null($normalizedData['data'])) {
                        $normalizedData['data'] = [];
                    }
                    
                    // Recursively ensure all nested arrays are arrays
                    $normalizeArrays = function(&$item) use (&$normalizeArrays) {
                        if (is_array($item)) {
                            foreach ($item as &$value) {
                                if (is_array($value) && empty($value)) {
                                    $value = [];
                                } elseif (is_array($value)) {
                                    $normalizeArrays($value);
                                }
                            }
                        }
                    };
                    $normalizeArrays($normalizedData);
                }
            }
            
            // Laravel automatically formats dates as ISO 8601 and booleans as true/false in JSON responses
            // Translate message if it's a translation key, otherwise use as-is
            $translatedMessage = $message;
            if ($message) {
                // Check if it's a translation key
                if (Lang::has('messages.' . $message)) {
                    $translatedMessage = Lang::get('messages.' . $message);
                } elseif (Lang::has($message)) {
                    $translatedMessage = Lang::get($message);
                } else {
                    // Try to auto-translate common success message patterns
                    $messageLower = strtolower($message);
                    if (str_contains($messageLower, 'created successfully')) {
                        $translatedMessage = Lang::get('messages.created_successfully');
                    } elseif (str_contains($messageLower, 'updated successfully')) {
                        $translatedMessage = Lang::get('messages.updated_successfully');
                    } elseif (str_contains($messageLower, 'deleted successfully')) {
                        $translatedMessage = Lang::get('messages.deleted_successfully');
                    } elseif (str_contains($messageLower, 'retrieved successfully')) {
                        $translatedMessage = Lang::get('messages.retrieved_successfully');
                    } elseif (str_contains($messageLower, 'uploaded successfully')) {
                        $translatedMessage = Lang::get('messages.uploaded_successfully');
                    } elseif (str_contains($messageLower, 'removed successfully')) {
                        $translatedMessage = Lang::get('messages.removed_successfully');
                    } elseif (str_contains($messageLower, 'imported successfully')) {
                        $translatedMessage = Lang::get('messages.data_imported_successfully');
                    }
                }
            } else {
                $translatedMessage = Lang::get('messages.operation_success');
            }
            
            return response()->json([
                'success' => true,
                'message' => $translatedMessage,
                'data' => $normalizedData,
                'errors' => null,
            ]);
        });

        /**
         * Standard error response macro
         * Format: { success: false, message: string, data: null, errors: { code?: string, details: mixed } }
         */
        Response::macro('error', function($message, $statusCode = 400, $errors = null, $code = null) {
            // Handle validation errors (Illuminate\Support\MessageBag)
            $errorDetails = null;
            $localizedErrors = null;
            
            if ($errors instanceof \Illuminate\Support\MessageBag || $errors instanceof \Illuminate\Contracts\Support\MessageBag) {
                $errorDetails = $errors->toArray();
                // Localize validation error messages
                $localizedErrors = [];
                foreach ($errorDetails as $field => $messages) {
                    $localizedErrors[$field] = is_array($messages) 
                        ? array_map(function($msg) {
                            // If message is already translated, use it; otherwise try to translate
                            return Lang::has('validation.' . $msg) ? Lang::get('validation.' . $msg) : $msg;
                        }, $messages)
                        : $messages;
                }
            } elseif (is_array($errors)) {
                $errorDetails = $errors;
                $localizedErrors = $errors;
            } elseif (is_string($errors)) {
                $errorDetails = $errors;
                $localizedErrors = $errors;
            } elseif (!is_null($errors)) {
                $errorDetails = $errors;
                $localizedErrors = $errors;
            }
            
            // Translate main error message
            $translatedMessage = $message;
            if ($message && Lang::has('messages.' . $message)) {
                $translatedMessage = Lang::get('messages.' . $message);
            } elseif ($message && Lang::has($message)) {
                $translatedMessage = Lang::get($message);
            } elseif (!$message) {
                $translatedMessage = Lang::get('messages.an_error_occurred');
            }
            
            $response = [
                'success' => false,
                'message' => $translatedMessage,
                'data' => null,
                'errors' => null,
            ];
            
            if ($localizedErrors !== null) {
                $response['errors'] = [];
                if ($code) {
                    $response['errors']['code'] = $code;
                }
                // Format validation errors for frontend
                if (is_array($localizedErrors) && !empty($localizedErrors)) {
                    $formattedErrors = [];
                    foreach ($localizedErrors as $field => $fieldErrors) {
                        if (is_array($fieldErrors)) {
                            foreach ($fieldErrors as $error) {
                                $formattedErrors[] = [
                                    'field' => $field,
                                    'message' => $error,
                                ];
                            }
                        } else {
                            $formattedErrors[] = [
                                'field' => $field,
                                'message' => $fieldErrors,
                            ];
                        }
                    }
                    $response['errors']['details'] = $formattedErrors;
                } else {
                    $response['errors']['details'] = $localizedErrors;
                }
            }
            
            return response()->json($response, $statusCode);
        });
    }
}
