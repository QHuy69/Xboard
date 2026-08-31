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
    protected string $apiUrl;

    public function __construct(?string $token = null)
    {
        $botToken = (string) admin_setting('telegram_bot_token', $token);
        $this->apiUrl = "https://api.telegram.org/bot{$botToken}/";
    }

    public function sendMessage(int|string $chatId, string $text, string $parseMode = '', array $replyMarkup = []): void
    {
        if ($parseMode === 'markdown') {
            // Dynamic values are escaped by their caller before interpolation.
            // Escape only raw underscores here so an existing `\_` never turns
            // into `\\_`, which Telegram parses as a literal slash followed by
            // an unescaped Markdown delimiter.
            $text = preg_replace_callback(
                '/(?<!\\\\)_/',
                static fn (): string => '\_',
                $text,
            ) ?? $text;
        }

        $params = [
            'chat_id' => (string) $chatId,
            'text' => $text,
            'parse_mode' => $parseMode ?: null,
        ];
        if ($replyMarkup !== []) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->request('sendMessage', $params);
    }

    public function sendDocument(int|string $chatId, string $filePath, string $caption = ''): object
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
                    'chat_id' => (string) $chatId,
                    'caption' => $caption,
                ], static fn ($value) => $value !== ''));

            return $this->validateResponse($response, 'sendDocument');
        } catch (\Throwable $e) {
            Log::error('Telegram document upload failed', [
                'size' => filesize($filePath) ?: null,
                'error_type' => $e::class,
            ]);
            throw new ApiException('Telegram document upload failed');
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

    public function approveChatJoinRequest(int|string $chatId, int|string $userId): void
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => (string) $chatId,
            'user_id' => (string) $userId,
        ]);
    }

    public function declineChatJoinRequest(int|string $chatId, int|string $userId): void
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => (string) $chatId,
            'user_id' => (string) $userId,
        ]);
    }

    public function getMe(): object
    {
        return $this->request('getMe', retryable: true);
    }

    public function setWebhook(string $url): object
    {
        $result = $this->request('setWebhook', ['url' => $url], retryable: true);
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
            ], retryable: true);

            $localizedCommands = HookManager::filter('telegram.bot.commands.localized', []);
            foreach ($localizedCommands as $languageCode => $languageCommands) {
                if (!is_array($languageCommands) || $languageCommands === []) {
                    continue;
                }
                $this->request('setMyCommands', [
                    'commands' => json_encode($languageCommands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'scope' => json_encode(['type' => 'default']),
                    'language_code' => (string) $languageCode,
                ], retryable: true);
            }

            Log::info('Telegram Bot commands registered', [
                'commands_count' => count($commands),
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Bot command registration failed', [
                'error_type' => $e::class,
            ]);
        }
    }

    /** Get the currently registered command list. */
    public function getMyCommands(): object
    {
        return $this->request('getMyCommands', retryable: true);
    }

    /** Delete all registered commands. */
    public function deleteMyCommands(): object
    {
        return $this->request('deleteMyCommands', retryable: true);
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

    protected function request(string $method, array $params = [], bool $retryable = false): object
    {
        try {
            // Never keep retry state on a shared PendingRequest. Mutating calls
            // such as sendMessage must be attempted exactly once because a
            // timeout can happen after Telegram accepted the message. Only
            // read or replace-style idempotent methods opt into retries.
            $response = $this->http($retryable)
                ->post($this->apiUrl . $method, $params);

            return $this->validateResponse($response, $method);

        } catch (\Exception $e) {
            Log::error('Telegram API request failed', [
                'method' => $method,
                // Exception messages from the HTTP client may contain the bot
                // token and the complete GET query. Keep only its class.
                'error_type' => $e::class,
            ]);

            throw new ApiException('Telegram service request failed');
        }
    }

    protected function http(bool $retryable): PendingRequest
    {
        $request = Http::timeout(30)->withHeaders([
            'Accept' => 'application/json',
        ]);

        return $retryable ? $request->retry(3, 1000) : $request;
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
