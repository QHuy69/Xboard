<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Plugin as PluginModel;
use App\Services\Plugin\HookManager;
use App\Services\TelegramBindingService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function getBotInfo(
        Request $request,
        TelegramBindingService $bindingService,
        TelegramService $telegramService
    )
    {
        $user = $request->user();
        $plugin = PluginModel::query()->where('code', 'telegram')->first();
        $token = trim((string) admin_setting('telegram_bot_token', ''));
        $globallyEnabled = filter_var(
            admin_setting('telegram_bot_enable', false),
            FILTER_VALIDATE_BOOLEAN
        ) === true;
        $enabled = $globallyEnabled && (bool) $plugin?->is_enabled && $token !== '';
        $pluginConfig = json_decode((string) ($plugin?->config ?? ''), true);
        if (!is_array($pluginConfig)) {
            $pluginConfig = [];
        }
        $resellerFeatureEnabled = filter_var(
            $pluginConfig['enable_reseller_bot'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        ) === true;
        $resellerEnabled = $resellerFeatureEnabled && (bool) $user->is_reseller;

        $data = [
            'enabled' => $enabled,
            'linked' => $user->telegram_id !== null,
            'username' => null,
            'bind_url' => null,
            'binding_expires_in' => null,
            'capabilities' => [
                'reseller' => $enabled && $resellerEnabled,
                'support_admin' => $enabled && (bool) $user->is_admin,
            ],
        ];

        if (!$enabled) {
            return $this->success($data);
        }

        try {
            $cacheKey = 'telegram:bot:username:' . hash('sha256', $token);
            $username = Cache::remember($cacheKey, now()->addHour(), function () use ($telegramService): string {
                $response = $telegramService->getMe();
                $username = trim((string) ($response->result->username ?? ''));
                if (preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) !== 1) {
                    throw new \RuntimeException('Telegram returned an invalid bot username.');
                }
                return $username;
            });

            $data['username'] = $username;
            if ($user->telegram_id !== null) {
                // Telegram treats the start payload as an internal command,
                // which rebuilds the bound user's inline-button menu without
                // exposing any account or subscription credential in the URL.
                $data['bind_url'] = 'https://t.me/' . $username . '?start=menu';
            } else {
                $issued = $bindingService->issue($user);
                $data['binding_expires_in'] = (int) $issued['expires_in'];
                $data['bind_url'] = 'https://t.me/' . $username
                    . '?start=' . rawurlencode($issued['payload']);
            }
        } catch (\Throwable $e) {
            // The customer endpoint stays available when Telegram has a
            // transient outage, but never returns a non-functional bind URL.
            Log::warning('Telegram bot information could not be prepared', [
                'user_id' => (int) $user->id,
                'error_type' => $e::class,
            ]);
        }

        return $this->success($data);
    }

    public function unbind(Request $request, TelegramBindingService $bindingService)
    {
        // Revoke the bearer link before changing the database. A consumer that
        // already pulled it must acquire the same user lock and will observe
        // that its pointer was revoked before it can bind.
        $bindingService->revoke($request->user());

        $user = DB::transaction(function () use ($request) {
            $user = $request->user()->newQuery()
                ->whereKey($request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            $user->telegram_id = null;
            $user->saveOrFail();
            return $user;
        });

        HookManager::call('user.telegram.unbind.after', [$user]);

        return $this->success(true);
    }
}
