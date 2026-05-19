<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Priority: 1. Query param (?lang=ar|en), 2. Accept-Language header, 3. Default 'en'
        $locale = 'en';
        
        // Check query parameter first
        if ($request->has('lang')) {
            $lang = $request->query('lang');
            if (in_array($lang, ['ar', 'en'])) {
                $locale = $lang;
            }
        }
        // Check Accept-Language header
        elseif ($request->hasHeader('Accept-Language')) {
            $acceptLanguage = $request->header('Accept-Language');
            // Parse Accept-Language header (e.g., "ar,en;q=0.9" or "ar")
            $languages = explode(',', $acceptLanguage);
            if (!empty($languages)) {
                $primaryLang = trim(explode(';', $languages[0])[0]);
                if (in_array($primaryLang, ['ar', 'en'])) {
                    $locale = $primaryLang;
                }
            }
        }
        
        // Set the application locale
        App::setLocale($locale);
        
        // Store locale in request for later use
        $request->merge(['_locale' => $locale]);
        
        return $next($request);
    }
}

