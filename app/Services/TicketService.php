<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;

class TicketService
{
    public function reply($ticket, $message, $userId)
    {
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            $isAdmin = $userId !== $ticket->user_id;
            $ticket->reply_status = $isAdmin
                ? Ticket::REPLY_STATUS_REPLIED
                : Ticket::REPLY_STATUS_WAITING;
            $ticket->last_reply_user_id = $userId;
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId): void
    {
        $ticket = Ticket::where('id', $ticketId)->first();
        if (!$ticket) {
            throw new ApiException(__('Ticket does not exist'));
        }
        $ticketMessage = $this->reply($ticket, $message, $userId);
        if (!$ticketMessage) {
            throw new ApiException(__('Ticket reply failed'));
        }
        HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message)
    {
        try {
            DB::beginTransaction();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                DB::rollBack();
                throw new ApiException(__('There are other unresolved tickets'));
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level,
                'reply_status' => Ticket::REPLY_STATUS_WAITING,
                'last_reply_user_id' => $userId,
            ]);
            if (!$ticket) {
                throw new ApiException(__('Failed to open ticket'));
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                DB::rollBack();
                throw new ApiException(__('Failed to open ticket'));
            }
            DB::commit();
            return $ticket;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        if (!$user) {
            return;
        }
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            $locale = str_replace('_', '-', (string) ($user->locale ?: 'vi-VN'));
            $copy = $this->getReplyEmailCopy(
                $locale,
                (string) admin_setting('app_name', 'XBoard'),
                (string) $ticket->subject,
                (string) $ticketMessage->message
            );
            SendEmailJob::dispatch([
                'email' => $user->email,
                'language' => $locale,
                'subject' => $copy['subject'],
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => $copy['content'],
                    'content_mode' => 'text'
                ]
            ]);
        }
    }

    private function getReplyEmailCopy(
        string $locale,
        string $appName,
        string $ticketSubject,
        string $reply
    ): array {
        $locale = strtolower($locale);
        $language = match (true) {
            str_starts_with($locale, 'en') => 'en',
            str_starts_with($locale, 'zh-tw'),
            str_starts_with($locale, 'zh-hk'),
            str_starts_with($locale, 'zh-mo') => 'zh-TW',
            str_starts_with($locale, 'zh') => 'zh-CN',
            str_starts_with($locale, 'ja') => 'ja',
            str_starts_with($locale, 'ko') => 'ko',
            str_starts_with($locale, 'fa') => 'fa',
            str_starts_with($locale, 'ru') => 'ru',
            default => 'vi',
        };

        $labels = [
            'vi' => [
                'subject' => "Ticket của bạn trên {$appName} đã được phản hồi",
                'topic' => 'Chủ đề',
                'reply' => 'Nội dung phản hồi',
            ],
            'en' => [
                'subject' => "Your ticket on {$appName} has received a reply",
                'topic' => 'Subject',
                'reply' => 'Reply',
            ],
            'zh-CN' => [
                'subject' => "您在 {$appName} 的工单已得到回复",
                'topic' => '主题',
                'reply' => '回复内容',
            ],
            'zh-TW' => [
                'subject' => "您在 {$appName} 的工單已得到回覆",
                'topic' => '主題',
                'reply' => '回覆內容',
            ],
            'ja' => [
                'subject' => "{$appName} のチケットに返信がありました",
                'topic' => '件名',
                'reply' => '返信内容',
            ],
            'ko' => [
                'subject' => "{$appName} 티켓에 답변이 등록되었습니다",
                'topic' => '제목',
                'reply' => '답변 내용',
            ],
            'fa' => [
                'subject' => "برای تیکت شما در {$appName} پاسخی ثبت شد",
                'topic' => 'موضوع',
                'reply' => 'متن پاسخ',
            ],
            'ru' => [
                'subject' => "На ваше обращение в {$appName} поступил ответ",
                'topic' => 'Тема',
                'reply' => 'Ответ',
            ],
        ][$language];

        return [
            'subject' => $labels['subject'],
            'content' => "{$labels['topic']}: {$ticketSubject}\r\n{$labels['reply']}: {$reply}",
        ];
    }
}
