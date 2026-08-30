<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected PendingRequest $http;
    protected string $apiUrl;

    public function __construct(?string $token = null)
    {
        $botToken = admin_setting('telegram_bot_token', $token);
        $this->apiUrl = "https://api.telegram.org/bot{$botToken}/";

        $this->http = Http::timeout(30)
            ->retry(3, 1000)
            ->withHeaders([
                'Accept' => 'application/json',
            ]);
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '', array $replyMarkup = []): void
    {
        $text = $parseMode === 'markdown' ? str_replace('_', '\_', $text) : $text;

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode ?: null,
        ];
        if ($replyMarkup !== []) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->request('sendMessage', $params);
    }

    public function sendDocument(int $chatId, string $filePath, string $caption = ''): object
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ApiException('Telegram document is not readable');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) throw new ApiException('Cannot open Telegram document');

        try {
            $response = Http::timeout(180)
                ->withHeaders(['Accept' => 'application/json'])
                ->attach('document', $handle, basename($filePath))
                ->post($this->apiUrl . 'sendDocument', array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption,
                ], static fn ($value) => $value !== ''));

            return $this->validateResponse($response, 'sendDocument');
        } catch (\Throwable $e) {
            Log::error('Telegram document upload failed', [
                'chat_id' => $chatId,
                'size' => filesize($filePath) ?: null,
                'error' => $e->getMessage(),
            ]);
            throw new ApiException("Telegram service error: {$e->getMessage()}");
        } finally {
            fclose($handle);
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        $this->request('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], static fn ($value) => $value !== ''));
    }

    public function approveChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId): void
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function getMe(): object
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url): object
    {
        $result = $this->request('setWebhook', ['url' => $url]);
        return $result;
    }

    /** Register the Bot command list. */
    public function registerBotCommands(): void
    {
        try {
            $commands = HookManager::filter('telegram.bot.commands', []);

            if (empty($commands)) {
                Log::warning('No Telegram Bot commands were registered');
                return;
            }

            $this->request('setMyCommands', [
                'commands' => json_encode($commands),
                'scope' => json_encode(['type' => 'default'])
            ]);

            $localizedCommands = HookManager::filter('telegram.bot.commands.localized', []);
            foreach ($localizedCommands as $languageCode => $languageCommands) {
                if (!is_array($languageCommands) || $languageCommands === []) {
                    continue;
                }
                $this->request('setMyCommands', [
                    'commands' => json_encode($languageCommands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'scope' => json_encode(['type' => 'default']),
                    'language_code' => (string) $languageCode,
                ]);
            }

            Log::info('Telegram Bot commands registered', [
                'commands_count' => count($commands),
                'commands' => $commands
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Bot command registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /** Get the currently registered command list. */
    public function getMyCommands(): object
    {
        return $this->request('getMyCommands');
    }

    /** Delete all registered commands. */
    public function deleteMyCommands(): object
    {
        return $this->request('deleteMyCommands');
    }

    public function sendMessageWithAdmin(string $message, bool $isStaff = false): void
    {
        $this->sendMessageWithAdminLocalized(fn () => $message, $isStaff);
    }

    /**
     * Send a locale-aware message to every bound administrator (and optionally
     * staff member). The callback receives the recipient User model.
     */
    public function sendMessageWithAdminLocalized(callable $messageFactory, bool $isStaff = false): void
    {
        $query = User::where('telegram_id', '!=', null);
        $query->where(
            fn($q) => $q->where('is_admin', 1)
                ->when($isStaff, fn($q) => $q->orWhere('is_staff', 1))
        );
        $users = $query->get();
        foreach ($users as $user) {
            $message = (string) $messageFactory($user);
            if ($message !== '') {
                SendTelegramJob::dispatch($user->telegram_id, $message);
            }
        }
    }

    protected function request(string $method, array $params = []): object
    {
        try {
            $response = $this->http->get($this->apiUrl . $method, $params);

            return $this->validateResponse($response, $method);

        } catch (\Exception $e) {
            Log::error('Telegram API request failed', [
                'method' => $method,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            throw new ApiException("Telegram service error: {$e->getMessage()}");
        }
    }

    protected function validateResponse(\Illuminate\Http\Client\Response $response, string $method): object
    {
        if (!$response->successful()) {
            throw new ApiException("Telegram {$method} HTTP request failed: {$response->status()}");
        }

        $data = $response->object();
        if (!isset($data->ok)) throw new ApiException('Invalid Telegram API response');
        if (!$data->ok) {
            $description = $data->description ?? 'unknown error';
            throw new ApiException("Telegram API error: {$description}");
        }

        return $data;
    }
}
