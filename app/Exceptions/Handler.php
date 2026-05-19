<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->error('resource_not_found', 404);
            }
        });

        $this->renderable(function (MethodNotAllowedHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->error('an_error_occurred', 405);
            }
        });

        $this->renderable(function (AuthorizationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->error('unauthorized_access', 403);
            }
        });

        $this->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->error('unauthenticated', 401);
            }
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $e)
    {
        if ($e instanceof AuthenticationException && $request->is('api/*')) {
            return response()->error('unauthenticated', 401);
        }

        if ($e instanceof AuthorizationException && $request->is('api/*')) {
            return response()->error('unauthorized_access', 403);
        }

        // Handle validation exceptions for API requests
        if ($e instanceof ValidationException && $request->is('api/*')) {
            return response()->error(
                'validation_failed',
                422,
                $e->errors(),
                'VALIDATION_ERROR'
            );
        }

        // Handle HTTP exceptions for API requests
        if ($e instanceof HttpException && $request->is('api/*')) {
            $statusCode = $e->getStatusCode();
            $message = $e->getMessage();
            
            // Try to translate common HTTP error messages
            $translationKey = null;
            if ($statusCode === 404) {
                $translationKey = 'resource_not_found';
            } elseif ($statusCode === 403) {
                $translationKey = 'unauthorized_access';
            } elseif ($statusCode === 401) {
                $translationKey = 'unauthenticated';
            }
            
            return response()->error($translationKey ?: $message, $statusCode);
        }

        // Handle all other exceptions for API requests
        if ($request->is('api/*')) {
            // In production, don't expose internal error details
            $message = config('app.debug') 
                ? $e->getMessage() 
                : 'an_error_occurred';
            
            return response()->error($message, 500, null, 'INTERNAL_ERROR');
        }

        return parent::render($request, $e);
    }
}
