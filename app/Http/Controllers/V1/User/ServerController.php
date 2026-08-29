<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    private const SUPPORTED_LOCALES = ['zh-CN', 'zh-TW', 'en-US', 'vi-VN', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        $this->rememberLocale($request, $user);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        // Node responses now contain user-specific access URLs. Scope the
        // validator to the authenticated user so a browser can never reuse a
        // different account's node credentials after an account switch.
        $eTag = sha1(json_encode([
            'user' => [$user->id, $user->uuid],
            'servers' => array_column($servers, 'cache_key'),
        ]));
        if (strpos($request->header('If-None-Match', ''), $eTag) !== false ) {
            return response(null,304)
                ->header('ETag', "\"{$eTag}\"")
                ->header('Cache-Control', 'private, no-store');
        }
        $data = NodeResource::collection($servers);
        return response([
            'data' => $data
        ])->header('ETag', "\"{$eTag}\"")
            ->header('Cache-Control', 'private, no-store');
    }

    private function rememberLocale(Request $request, User $user): void
    {
        $locale = str_replace('_', '-', (string) $request->cookie('luck_locale', ''));
        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            foreach ($request->getLanguages() as $language) {
                $language = str_replace('_', '-', (string) $language);
                if (in_array($language, self::SUPPORTED_LOCALES, true)) {
                    $locale = $language;
                    break;
                }
                $base = strtolower(explode('-', $language)[0]);
                foreach (self::SUPPORTED_LOCALES as $candidate) {
                    if (strtolower(explode('-', $candidate)[0]) === $base) {
                        $locale = $candidate;
                        break 2;
                    }
                }
            }
        }
        if ($locale !== '' && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }
    }
}
