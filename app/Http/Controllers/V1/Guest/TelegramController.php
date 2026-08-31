<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramController extends Controller
{
    private const UPDATE_DEDUPE_HOURS = 36;
    private const UPDATE_RECEIPTS_TABLE = 'telegram_webhook_update_receipts';
    private const MAX_SIGNED_BIGINT = '9223372036854775807';
    private const MIN_SIGNED_BIGINT_MAGNITUDE = '9223372036854775808';

    protected ?object $msg = null;
    protected TelegramService $telegramService;
    protected UserService $userService;

    public function __construct(TelegramService $telegramService, UserService $userService)
    {
        $this->telegramService = $telegramService;
        $this->userService = $userService;
    }

    public function webhook(Request $request): void
    {
        $botToken = trim((string) admin_setting('telegram_bot_token', ''));
        if (!$this->integrationEnabled($botToken)) {
            return;
        }

        $expectedToken = md5($botToken);
        $providedToken = $request->query('access_token');
        if (!is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
            throw new ApiException('access_token is error', 401);
        }

        $data = $request->json()->all();

        if (!$this->claimUpdate($data['update_id'] ?? null, $botToken)) {
            return;
        }

        $this->formatMessage($data);
        $this->formatCallbackQuery($data);
        $this->formatChatJoinRequest($data);
        $this->handle();
    }

    private function handle(): void
    {
        if (!$this->msg)
            return;
        $msg = $this->msg;
        $this->processBotName($msg);
        try {
            HookManager::call('telegram.message.before', [$msg]);
            $handled = HookManager::filter('telegram.message.handle', false, [$msg]);
            if (!$handled) {
                HookManager::call('telegram.message.unhandled', [$msg]);
            }
            HookManager::call('telegram.message.after', [$msg]);
        } catch (\Exception $e) {
            HookManager::call('telegram.message.error', [$msg, $e]);
            $this->telegramService->sendMessage(
                $msg->chat_id,
                $this->fallbackErrorForLocale((string) ($msg->language_code ?? ''))
            );
        }
    }

    private function fallbackErrorForLocale(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        if (in_array($locale, ['zh-tw', 'zh-hk', 'zh-mo', 'zh-hant'], true)) {
            return '系統忙碌中，請稍後再試。';
        }

        return match (explode('-', $locale)[0] ?? '') {
            'vi' => 'Hệ thống đang bận, vui lòng thử lại sau.',
            'zh' => '系统繁忙，请稍后重试。',
            'ja' => 'システムが混み合っています。しばらくしてからもう一度お試しください。',
            'ko' => '시스템이 혼잡합니다. 잠시 후 다시 시도해 주세요.',
            'fa' => 'سامانه مشغول است. لطفاً کمی بعد دوباره تلاش کنید.',
            'ru' => 'Система занята. Повторите попытку позже.',
            default => 'The system is busy. Please try again later.',
        };
    }

    private function processBotName(object $msg): void
    {
        $commandParts = explode('@', $msg->command);

        if (count($commandParts) === 2) {
            $botName = $this->getBotName();
            if ($commandParts[1] === $botName) {
                $msg->command = $commandParts[0];
            }
        }
    }

    private function getBotName(): string
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data): void
    {
        if (!isset($data['message']['text']))
            return;

        $message = $data['message'];
        $chatId = $this->chatId($message['chat']['id'] ?? null);
        $fromId = $this->actorId($message['from']['id'] ?? $message['chat']['id'] ?? null);
        if ($chatId === null || $fromId === null) {
            return;
        }

        $messageText = (string) $message['text'];
        $text = explode(' ', $messageText);

        $this->msg = (object) [
            'command' => $text[0],
            'args' => array_slice($text, 1),
            'chat_id' => $chatId,
            'from_id' => $fromId,
            'message_id' => isset($message['message_id']) ? (string) $message['message_id'] : null,
            'message_type' => 'message',
            'text' => $messageText,
            'is_private' => ($message['chat']['type'] ?? '') === 'private',
            'language_code' => $message['from']['language_code'] ?? null,
        ];

        if (isset($message['reply_to_message']['text'])) {
            $this->msg->message_type = 'reply_message';
            $this->msg->reply_text = $message['reply_to_message']['text'];
        }
    }

    private function formatCallbackQuery(array $data): void
    {
        $callback = $data['callback_query'] ?? null;
        if (!$callback || !isset($callback['data'], $callback['message']['chat']['id'], $callback['id'])) {
            return;
        }

        $chat = $callback['message']['chat'];
        $chatId = $this->chatId($chat['id'] ?? null);
        $fromId = $this->actorId($callback['from']['id'] ?? $chat['id'] ?? null);
        if ($chatId === null || $fromId === null) {
            return;
        }

        $this->msg = (object) [
            'command' => (string) $callback['data'],
            'args' => [],
            'chat_id' => $chatId,
            'from_id' => $fromId,
            'message_id' => isset($callback['message']['message_id'])
                ? (string) $callback['message']['message_id']
                : null,
            'callback_query_id' => (string) $callback['id'],
            'message_type' => 'callback_query',
            'text' => (string) $callback['data'],
            'is_private' => ($chat['type'] ?? '') === 'private',
            'language_code' => $callback['from']['language_code'] ?? null,
        ];
    }

    private function formatChatJoinRequest(array $data): void
    {
        $joinRequest = $data['chat_join_request'] ?? null;
        if (!$joinRequest)
            return;

        $chatId = $this->chatId($joinRequest['chat']['id'] ?? null);
        $userId = $this->actorId($joinRequest['from']['id'] ?? null);

        if ($chatId === null || $userId === null)
            return;

        $user = User::where('telegram_id', $userId)->first();

        if (!$user) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest($chatId, $userId);
            return;
        }

        $this->telegramService->approveChatJoinRequest($chatId, $userId);
    }

    private function integrationEnabled(string $botToken): bool
    {
        if ($botToken === '' || filter_var(
            admin_setting('telegram_bot_enable', false),
            FILTER_VALIDATE_BOOLEAN
        ) !== true) {
            return false;
        }

        return PluginModel::query()
            ->where('code', 'telegram')
            ->where('is_enabled', true)
            ->exists();
    }

    private function claimUpdate(mixed $updateId, string $botToken): bool
    {
        if (is_int($updateId)) {
            $updateId = (string) $updateId;
        }

        if (!is_string($updateId)
            || preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $updateId) !== 1) {
            return false;
        }

        $receiptHash = hash(
            'sha256',
            hash('sha256', $botToken, true) . "\0" . $updateId
        );
        $claimedAt = now();

        // Keep the durable claim and retention cleanup in one transaction. A
        // database or schema failure aborts the request before any bot side
        // effect, allowing Telegram to retry after the dependency recovers.
        return DB::transaction(function () use ($receiptHash, $claimedAt): bool {
            DB::table(self::UPDATE_RECEIPTS_TABLE)
                ->where('expires_at', '<=', $claimedAt)
                ->delete();

            return DB::table(self::UPDATE_RECEIPTS_TABLE)->insertOrIgnore([
                'receipt_hash' => $receiptHash,
                'created_at' => $claimedAt,
                'expires_at' => $claimedAt->copy()->addHours(self::UPDATE_DEDUPE_HOURS),
            ]) === 1;
        }, 3);
    }

    private function actorId(mixed $value): ?string
    {
        return $this->decimalId($value, false);
    }

    private function chatId(mixed $value): ?string
    {
        return $this->decimalId($value, true);
    }

    private function decimalId(mixed $value, bool $allowNegative): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        if ($negative && !$allowNegative) {
            return null;
        }

        $magnitude = $negative ? substr($value, 1) : $value;
        if (preg_match('/^[1-9][0-9]{0,18}$/', $magnitude) !== 1) {
            return null;
        }

        $maximum = $negative
            ? self::MIN_SIGNED_BIGINT_MAGNITUDE
            : self::MAX_SIGNED_BIGINT;
        if (strlen($magnitude) > strlen($maximum)
            || (strlen($magnitude) === strlen($maximum) && strcmp($magnitude, $maximum) > 0)) {
            return null;
        }

        return $value;
    }
}
