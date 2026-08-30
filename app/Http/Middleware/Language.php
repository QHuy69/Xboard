<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class Language
{
    public function handle($request, Closure $next)
    {
        $supported = ['zh-CN', 'zh-TW', 'en-US', 'vi-VN', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];
        $requested = $request->header('content-language')
            ?: $request->cookie('luck_locale')
            ?: $request->header('accept-language');

        if (is_string($requested) && $requested !== '') {
            $candidates = preg_split('/\s*,\s*/', $requested) ?: [];
            foreach ($candidates as $candidate) {
                $candidate = str_replace('_', '-', trim(explode(';', $candidate, 2)[0]));
                foreach ($supported as $locale) {
                    if (strcasecmp($candidate, $locale) === 0
                        || strcasecmp(explode('-', $candidate, 2)[0], explode('-', $locale, 2)[0]) === 0) {
                        App::setLocale($locale);
                        break 2;
                    }
                }
            }
        }
        return $next($request);
    }
}
