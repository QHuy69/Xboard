<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendTelegramJob;
use App\Models\Plugin as PluginModel;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Telegram accepts ISO 639-1 command-menu scopes. Chinese therefore has
     * one shared scope even though message bodies support zh-CN and zh-TW.
     */
    private const BOT_COMMAND_LANGUAGE_CODES = ['vi', 'en', 'zh', 'ja', 'ko', 'fa', 'ru'];
    private const BOT_COMMAND_SCOPE_TYPES = [
        'default',
        'all_private_chats',
        'all_group_chats',
        'all_chat_administrators',
    ];
    private const BOT_COMMAND_MENU_SCHEMA = 'inline-buttons-v2.3';
    private const BOT_COMMAND_MENU_FINGERPRINT_SETTING = 'telegram_bot_command_menu_fingerprint';

    protected string $apiUrl;
    protected bool $hasBotToken;
    protected string $commandMenuFingerprint;

    public function __construct(?string $token = null)
    {
        // An explicit token is used while the administrator is rotating bot
        // credentials; the stored setting is only a fallback. Reversing this
        // precedence can register the webhook on the old bot while hashing the
        // new form submission.
        $botToken = trim((string) ($token ?? admin_setting('telegram_bot_token', '')));
        $this->hasBotToken = $botToken !== '';
        $this->commandMenuFingerprint = hash(
            'sha256',
            implode('|', [
                self::BOT_COMMAND_MENU_SCHEMA,
                implode(',', self::BOT_COMMAND_SCOPE_TYPES),
                'default-language,' . implode(',', self::BOT_COMMAND_LANGUAGE_CODES),
                $botToken,
            ]),
        );
        $this->apiUrl = "https://api.telegram.org/bot{$botToken}/";
    }

    /**
     * Runtime deliveries require every integration switch to remain active.
     * Control-plane calls such as getMe, setWebhook and command-menu cleanup
     * deliberately bypass this gate so an administrator can configure or
     * repair the integration while enabling it.
     */
    public static function runtimeEnabled(): bool
    {
        $globallyEnabled = filter_var(
            admin_setting('telegram_bot_enable', false),
            FILTER_VALIDATE_BOOLEAN,
        ) === true;

        return $globallyEnabled
            && trim((string) admin_setting('telegram_bot_token', '')) !== ''
            && PluginModel::query()
                ->where('code', 'telegram')
                ->where('is_enabled', true)
                ->exists();
    }

    public function sendMessage(int|string $chatId, string $text, string $parseMode = '', array $replyMarkup = []): void
    {
        if (!self::runtimeEnabled()) return;

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
        if (!self::runtimeEnabled()) {
            throw new ApiException('Telegram runtime delivery is disabled');
        }
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
        if (!self::runtimeEnabled()) return;
        $this->request('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], static fn ($value) => $value !== ''));
    }

    public function approveChatJoinRequest(int|string $chatId, int|string $userId): void
    {
        if (!self::runtimeEnabled()) return;
        $this->request('approveChatJoinRequest', [
            'chat_id' => (string) $chatId,
            'user_id' => (string) $userId,
        ]);
    }

    public function declineChatJoinRequest(int|string $chatId, int|string $userId): void
    {
        if (!self::runtimeEnabled()) return;
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

    /** Keep the public Bot command menu empty; inline buttons remain available. */
    public function registerBotCommands(): bool
    {
        if (!$this->hasBotToken) return false;
        try {
            // Delete every scope explicitly so a previous deployment cannot
            // leave stale slash commands visible in one Telegram language.
            $this->deleteMyCommands();
            admin_setting([
                self::BOT_COMMAND_MENU_FINGERPRINT_SETTING => $this->commandMenuFingerprint,
            ]);
            Log::info('Telegram Bot command menu cleared');
            return true;

        } catch (\Throwable $e) {
            Log::error('Telegram Bot command menu cleanup failed', [
                'error_type' => $e::class,
            ]);
            return false;
        }
    }

    /** Whether this bot/token still needs the current empty-menu schema applied. */
    public function commandMenuNeedsReconciliation(): bool
    {
        if (!$this->hasBotToken) return false;
        $stored = trim((string) admin_setting(self::BOT_COMMAND_MENU_FINGERPRINT_SETTING, ''));
        return !hash_equals($this->commandMenuFingerprint, $stored);
    }

    /** Get the currently registered command list. */
    public function getMyCommands(): object
    {
        return $this->request('getMyCommands', retryable: true);
    }

    /** Delete all registered commands. */
    public function deleteMyCommands(): object
    {
        $operations = [];
        foreach (self::BOT_COMMAND_SCOPE_TYPES as $scopeType) {
            $scope = ['scope' => json_encode(['type' => $scopeType])];
            foreach ([null, ...self::BOT_COMMAND_LANGUAGE_CODES] as $languageCode) {
                $key = $scopeType . ':' . ($languageCode ?? 'default');
                $operations[$key] = [
                    'scope_type' => $scopeType,
                    'language_code' => $languageCode,
                    'params' => $scope + array_filter([
                        'language_code' => $languageCode,
                    ], static fn ($value) => $value !== null),
                ];
            }
        }

        // Run the finite global-scope matrix concurrently. A sequential retry
        // loop could block a deploy or scheduler worker for many minutes when
        // Telegram is unavailable; the scheduler already retries the complete
        // reconciliation until its token/schema fingerprint is persisted.
        $runPool = function (array $batch, int $concurrency): array {
            return Http::pool(function (Pool $pool) use ($batch): void {
                foreach ($batch as $key => $operation) {
                    $pool->as($key)
                        ->timeout(10)
                        ->withHeaders(['Accept' => 'application/json'])
                        ->post($this->apiUrl . 'deleteMyCommands', $operation['params']);
                }
            }, $concurrency);
        };
        $responses = $runPool($operations, 8);

        $result = null;
        $failedScopes = [];
        $rateLimitedScopes = [];
        $retryAfterSeconds = 0;
        foreach ($operations as $key => $operation) {
            try {
                $response = $responses[$key] ?? null;
                if (!$response instanceof Response) {
                    throw $response instanceof \Throwable
                        ? $response
                        : new \RuntimeException('Telegram command cleanup response is missing');
                }
                $validated = $this->validateResponse($response, 'deleteMyCommands');
                if (($validated->result ?? null) !== true) {
                    throw new ApiException('Telegram did not confirm command menu cleanup');
                }
                if ($operation['scope_type'] === 'default' && $operation['language_code'] === null) {
                    $result = $validated;
                }
            } catch (\Throwable $e) {
                $failedScopes[$key] = [
                    'operation' => $operation,
                    'error_type' => $e::class,
                ];
                if ($response instanceof Response
                    && ($response->status() === 429 || (int) $response->json('error_code', 0) === 429)) {
                    $rateLimitedScopes[$key] = $operation;
                    $retryAfterSeconds = max(
                        $retryAfterSeconds,
                        max(0, (int) $response->json('parameters.retry_after', 0)),
                    );
                }
            }
        }

        if ($rateLimitedScopes !== []) {
            $boundedDelay = min(15, $retryAfterSeconds);
            Log::warning('Telegram command-menu cleanup rate limited; retrying affected scopes', [
                'scope_count' => count($rateLimitedScopes),
                'retry_after_seconds' => $boundedDelay,
            ]);
            if ($boundedDelay > 0) sleep($boundedDelay);
            $retryResponses = $runPool($rateLimitedScopes, 4);
            foreach ($rateLimitedScopes as $key => $operation) {
                try {
                    $response = $retryResponses[$key] ?? null;
                    if (!$response instanceof Response) {
                        throw $response instanceof \Throwable
                            ? $response
                            : new \RuntimeException('Telegram command cleanup retry response is missing');
                    }
                    $validated = $this->validateResponse($response, 'deleteMyCommands');
                    if (($validated->result ?? null) !== true) {
                        throw new ApiException('Telegram did not confirm command menu cleanup');
                    }
                    if ($operation['scope_type'] === 'default' && $operation['language_code'] === null) {
                        $result = $validated;
                    }
                    unset($failedScopes[$key]);
                } catch (\Throwable $e) {
                    $failedScopes[$key]['error_type'] = $e::class;
                }
            }
        }

        if ($failedScopes !== []) {
            foreach ($failedScopes as $failure) {
                Log::error('Telegram command-menu scope cleanup failed', [
                    'scope_type' => $failure['operation']['scope_type'],
                    'language_code' => $failure['operation']['language_code'] ?? 'default',
                    'error_type' => $failure['error_type'],
                ]);
            }
            throw new ApiException('Telegram command menu cleanup was incomplete');
        }
        if (!$result) throw new ApiException('Telegram default command menu was not cleared');
        return $result;
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
        if (!self::runtimeEnabled()) return;

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
