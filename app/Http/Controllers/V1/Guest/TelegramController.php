<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\UserService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
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
        $expectedToken = md5(admin_setting('telegram_bot_token'));
        if ($request->input('access_token') !== $expectedToken) {
            throw new ApiException('access_token is error', 401);
        }

        $data = $request->json()->all();

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
            $language = strtolower((string) ($msg->language_code ?? ''));
            $error = str_starts_with($language, 'vi')
                ? 'Hệ thống đang bận, vui lòng thử lại sau.'
                : (str_starts_with($language, 'zh') ? '系统繁忙，请稍后重试。' : 'The system is busy. Please try again later.');
            $this->telegramService->sendMessage($msg->chat_id, $error);
        }
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
        $text = explode(' ', $message['text']);

        $this->msg = (object) [
            'command' => $text[0],
            'args' => array_slice($text, 1),
            'chat_id' => $message['chat']['id'],
            'from_id' => $message['from']['id'] ?? $message['chat']['id'],
            'message_id' => $message['message_id'],
            'message_type' => 'message',
            'text' => $message['text'],
            'is_private' => $message['chat']['type'] === 'private',
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
        $this->msg = (object) [
            'command' => (string) $callback['data'],
            'args' => [],
            'chat_id' => $chat['id'],
            'from_id' => $callback['from']['id'] ?? $chat['id'],
            'message_id' => $callback['message']['message_id'] ?? null,
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

        $chatId = $joinRequest['chat']['id'] ?? null;
        $userId = $joinRequest['from']['id'] ?? null;

        if (!$chatId || !$userId)
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
}
