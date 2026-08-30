<?php

namespace Plugin\Telegram;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Ticket;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\EncryptedDatabaseBackupService;
use App\Services\OrderService;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    protected array $commands = [];
    protected TelegramService $telegramService;

    protected array $commandConfigs = [
        '/start' => ['description' => 'Mở menu chính', 'handler' => 'handleStartCommand'],
        '/menu' => ['description' => 'Mở menu chính', 'handler' => 'handleStartCommand'],
        '/bind' => ['description' => 'Liên kết tài khoản', 'handler' => 'handleBindCommand'],
        '/traffic' => ['description' => 'Xem lưu lượng', 'handler' => 'handleTrafficCommand'],
        '/getlatesturl' => ['description' => 'Lấy liên kết đăng ký', 'handler' => 'handleGetLatestUrlCommand'],
        '/unbind' => ['description' => 'Hủy liên kết tài khoản', 'handler' => 'handleUnbindCommand'],
        '/nodes' => ['description' => 'Xem người dùng online theo node', 'handler' => 'handleNodesCommand'],
        '/setreportgroup' => ['description' => 'Đặt nhóm nhận báo cáo node', 'handler' => 'handleSetReportGroupCommand'],
        '/setbackupchat' => ['description' => 'Đặt nơi nhận backup database', 'handler' => 'handleSetBackupChatCommand'],
        '/backupdb' => ['description' => 'Backup database ngay', 'handler' => 'handleBackupDatabaseCommand'],
        '/reseller' => ['description' => 'Tạo tài khoản khách hàng', 'handler' => 'handleResellerCommand'],
        '/cancel' => ['description' => 'Hủy thao tác hiện tại', 'handler' => 'handleCancelCommand'],
    ];

    private array $messages = [
        'vi' => [
            'busy' => 'Hệ thống đang bận, vui lòng thử lại sau.', 'private' => 'Vui lòng dùng tính năng này trong chat riêng với bot.',
            'bind_first' => 'Vui lòng liên kết tài khoản trước.', 'unknown' => 'Tôi chưa hiểu yêu cầu. Hãy chọn một nút trong menu.',
            'welcome' => "🎉 Chào mừng đến với bot ZaoGuang!\n\nBot giúp bạn xem lưu lượng, lấy liên kết đăng ký và quản lý liên kết tài khoản.",
            'bound' => '✅ Tài khoản đang liên kết: :email', 'not_bound' => "🔗 Chưa liên kết tài khoản. Gửi:\n/bind [liên kết đăng ký]",
            'bad_bind' => 'Hãy gửi /bind kèm liên kết đăng ký.', 'invalid_url' => 'Liên kết đăng ký không hợp lệ.',
            'user_missing' => 'Không tìm thấy người dùng.', 'already_bound' => 'Tài khoản này đã liên kết với Telegram khác.',
            'bind_failed' => 'Không thể lưu liên kết tài khoản.', 'bind_ok' => '✅ Liên kết tài khoản thành công.',
            'unbind_failed' => 'Không thể hủy liên kết.', 'unbind_ok' => '✅ Đã hủy liên kết tài khoản.',
            'traffic' => "📊 Lưu lượng\n\nĐã dùng: :used GB\nTổng: :total GB\nCòn lại: :remaining GB\nTỷ lệ: :percent%",
            'url' => "🔗 Liên kết đăng ký của bạn:\n\n:url",
            'forbidden' => 'Bạn không có quyền dùng tính năng này.', 'cancelled' => 'Đã hủy thao tác hiện tại.',
            'nodes_title' => '🖥 Người dùng online theo node', 'node_line' => ':state :name (:type): :online người dùng',
            'report_group_ok' => '✅ Nhóm này sẽ nhận báo cáo node tự động.', 'report_group_only' => 'Lệnh này phải được gửi trong nhóm Telegram.',
            'backup_chat_ok' => '✅ Chat riêng này sẽ nhận backup database tự động.',
            'backup_private' => 'Vì an toàn, lệnh backup database chỉ dùng trong chat riêng với bot.',
            'backup_started' => '⏳ Đang tạo và mã hóa backup database...',
            'backup_config_invalid' => '⚠️ Chưa cấu hình mật khẩu backup tối thiểu 16 ký tự.',
            'backup_failed' => '❌ Backup database thất bại. Hãy kiểm tra log hệ thống.',
            'reseller_intro' => '🤝 Tạo tài khoản khách. Hãy nhập email khách hàng hoặc bấm Hủy.',
            'reseller_email_invalid' => 'Email không hợp lệ, vui lòng nhập lại.', 'reseller_email_exists' => 'Email này đã tồn tại.',
            'reseller_choose_plan' => 'Chọn gói cho :email:', 'reseller_no_plan' => 'Không có gói nào đang được phép bán.',
            'reseller_choose_period' => 'Chọn chu kỳ của gói :plan:', 'reseller_coupon' => 'Nhập mã giảm giá 100%:',
            'reseller_coupon_invalid' => 'Mã phải là coupon phần trăm 100%, còn hiệu lực và áp dụng được cho gói/chu kỳ đã chọn.',
            'reseller_done' => "✅ Đã tạo và kích hoạt tài khoản\n\nEmail: :email\nMật khẩu: :password\nGói: :plan\nChu kỳ: :period\nLiên kết: :url\n\nHãy gửi mật khẩu cho khách qua kênh an toàn.",
            'renewed' => '✅ Gia hạn thành công gói :plan. Hạn mới: :expires',
            'upgraded' => '✅ Đổi gói thành công sang :plan. Hạn mới: :expires',
            'purchased' => '✅ Kích hoạt thành công gói :plan. Hạn: :expires',
            'traffic_reset' => '✅ Lưu lượng của bạn đã được đặt lại.',
            'url_reset' => "🔐 Liên kết đăng ký đã được đặt lại:\n:url",
            'ticket_replied' => '✅ Ticket #:id đã được trả lời.',
            'button_traffic' => '📊 Lưu lượng', 'button_url' => '🔗 Link đăng ký', 'button_nodes' => '🖥 Node online',
            'button_reseller' => '🤝 CTV', 'button_cancel' => '❌ Hủy', 'button_create' => '➕ Tạo tài khoản',
        ],
        'en' => [
            'busy' => 'The system is busy. Please try again later.', 'private' => 'Please use this feature in a private chat with the bot.',
            'bind_first' => 'Please link your account first.', 'unknown' => 'I did not understand that. Choose an option from the menu.',
            'welcome' => "🎉 Welcome to the ZaoGuang bot!\n\nUse it to view traffic, get your subscription link, and manage account linking.",
            'bound' => '✅ Linked account: :email', 'not_bound' => "🔗 No linked account. Send:\n/bind [subscription link]",
            'bad_bind' => 'Send /bind followed by your subscription link.', 'invalid_url' => 'Invalid subscription link.',
            'user_missing' => 'User not found.', 'already_bound' => 'This account is linked to another Telegram account.',
            'bind_failed' => 'Could not save the account link.', 'bind_ok' => '✅ Account linked.',
            'unbind_failed' => 'Could not unlink the account.', 'unbind_ok' => '✅ Account unlinked.',
            'traffic' => "📊 Traffic\n\nUsed: :used GB\nTotal: :total GB\nRemaining: :remaining GB\nUsage: :percent%",
            'url' => "🔗 Your subscription link:\n\n:url", 'forbidden' => 'You are not allowed to use this feature.',
            'cancelled' => 'Current operation cancelled.', 'nodes_title' => '🖥 Online users by node',
            'node_line' => ':state :name (:type): :online users', 'report_group_ok' => '✅ This group will receive automatic node reports.',
            'report_group_only' => 'Send this command in a Telegram group.', 'reseller_intro' => '🤝 Enter the customer email or tap Cancel.',
            'backup_chat_ok' => '✅ This private chat will receive automatic database backups.',
            'backup_private' => 'For safety, database backup commands are only available in a private chat with the bot.',
            'backup_started' => '⏳ Creating and encrypting the database backup...',
            'backup_config_invalid' => '⚠️ Configure a database backup password with at least 16 characters first.',
            'backup_failed' => '❌ Database backup failed. Check the system log.',
            'reseller_email_invalid' => 'Invalid email. Try again.', 'reseller_email_exists' => 'This email already exists.',
            'reseller_choose_plan' => 'Choose a plan for :email:', 'reseller_no_plan' => 'No plans are currently for sale.',
            'reseller_choose_period' => 'Choose a billing period for :plan:', 'reseller_coupon' => 'Enter a 100% discount coupon:',
            'reseller_coupon_invalid' => 'The coupon must be a valid 100% percentage coupon for this plan and period.',
            'reseller_done' => "✅ Account created and activated\n\nEmail: :email\nPassword: :password\nPlan: :plan\nPeriod: :period\nLink: :url\n\nSend the password to the customer securely.",
            'renewed' => '✅ :plan renewed. New expiry: :expires', 'upgraded' => '✅ Plan changed to :plan. New expiry: :expires',
            'purchased' => '✅ :plan activated. Expiry: :expires', 'traffic_reset' => '✅ Your traffic was reset.',
            'url_reset' => "🔐 Your subscription link was reset:\n:url",
            'ticket_replied' => '✅ Ticket #:id was answered.',
            'button_traffic' => '📊 Traffic', 'button_url' => '🔗 Subscription link', 'button_nodes' => '🖥 Online nodes',
            'button_reseller' => '🤝 Reseller', 'button_cancel' => '❌ Cancel', 'button_create' => '➕ Create account',
        ],
        'zh' => [
            'busy' => '系统繁忙，请稍后重试。', 'private' => '请在私聊中使用此功能。', 'bind_first' => '请先绑定账号。',
            'unknown' => '无法识别该请求，请使用菜单按钮。', 'welcome' => "🎉 欢迎使用 ZaoGuang 机器人！\n\n您可以查看流量、获取订阅链接并管理账号绑定。",
            'bound' => '✅ 已绑定账号：:email', 'not_bound' => "🔗 尚未绑定，请发送：\n/bind [订阅链接]",
            'bad_bind' => '请发送 /bind 和订阅链接。', 'invalid_url' => '订阅链接无效。', 'user_missing' => '用户不存在。',
            'already_bound' => '该账号已绑定其他 Telegram。', 'bind_failed' => '绑定保存失败。', 'bind_ok' => '✅ 绑定成功。',
            'unbind_failed' => '解绑失败。', 'unbind_ok' => '✅ 解绑成功。',
            'traffic' => "📊 流量\n\n已用：:used GB\n总计：:total GB\n剩余：:remaining GB\n使用率：:percent%",
            'url' => "🔗 您的订阅链接：\n\n:url", 'forbidden' => '您无权使用此功能。', 'cancelled' => '已取消当前操作。',
            'nodes_title' => '🖥 各节点在线用户', 'node_line' => ':state :name (:type)：:online 人',
            'report_group_ok' => '✅ 本群将接收自动节点报告。', 'report_group_only' => '请在 Telegram 群组中发送此命令。',
            'backup_chat_ok' => '✅ 此私聊将接收自动数据库备份。',
            'backup_private' => '为确保安全，数据库备份命令只能在机器人私聊中使用。',
            'backup_started' => '⏳ 正在创建并加密数据库备份…',
            'backup_config_invalid' => '⚠️ 请先设置至少 16 个字符的数据库备份密码。',
            'backup_failed' => '❌ 数据库备份失败，请检查系统日志。',
            'reseller_intro' => '🤝 请输入客户邮箱，或点击取消。', 'reseller_email_invalid' => '邮箱无效，请重试。',
            'reseller_email_exists' => '该邮箱已存在。', 'reseller_choose_plan' => '为 :email 选择套餐：', 'reseller_no_plan' => '暂无可售套餐。',
            'reseller_choose_period' => '请选择 :plan 的周期：', 'reseller_coupon' => '请输入 100% 优惠码：',
            'reseller_coupon_invalid' => '优惠码必须是适用于所选套餐和周期的有效 100% 折扣码。',
            'reseller_done' => "✅ 账号已创建并开通\n\n邮箱：:email\n密码：:password\n套餐：:plan\n周期：:period\n链接：:url",
            'renewed' => '✅ :plan 续费成功，新到期时间：:expires', 'upgraded' => '✅ 已更换为 :plan，新到期时间：:expires',
            'purchased' => '✅ :plan 开通成功，到期时间：:expires', 'traffic_reset' => '✅ 流量已重置。',
            'url_reset' => "🔐 订阅链接已重置：\n:url",
            'ticket_replied' => '✅ 工单 #:id 已回复。',
            'button_traffic' => '📊 流量', 'button_url' => '🔗 订阅链接', 'button_nodes' => '🖥 在线节点',
            'button_reseller' => '🤝 合作伙伴', 'button_cancel' => '❌ 取消', 'button_create' => '➕ 创建账号',
        ],
    ];

    public function boot(): void
    {
        $this->telegramService = new TelegramService();
        foreach ($this->commandConfigs as $command => $config) {
            $this->commands['commands'][$command] = [$this, $config['handler']];
        }
        $this->commands['replies']['/(?:Ticket|工单|ticket).*?#?\s*(\d+)/iu'] = [$this, 'handleTicketReply'];
        $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
        $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
        $this->listen('telegram.message.error', [$this, 'handleError'], 10);
        $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
        $this->filter('telegram.bot.commands.localized', [$this, 'addLocalizedBotCommands'], 10);
        $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
        $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
        $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
        $this->listen('order.open.after', [$this, 'sendOrderLifecycleNotify'], 10);
        $this->listen('user.subscribe.reset.after', [$this, 'sendSubscriptionResetNotify'], 10);
        $this->listen('traffic.reset.telegram.after', [$this, 'sendTrafficResetNotify'], 10);
    }

    public function schedule(Schedule $schedule): void
    {
        // Schedule registration does not call boot(), so scheduled callbacks
        // must initialize their own Telegram client.
        if (!isset($this->telegramService)) $this->telegramService = new TelegramService();

        if ($this->getConfig('enable_node_group_report', false)) {
            $interval = (int) $this->getConfig('node_report_interval_minutes', 15);
            $event = $schedule->call(fn () => $this->sendScheduledNodeReport())->withoutOverlapping();
            match ($interval) { 5 => $event->everyFiveMinutes(), 60 => $event->hourly(), default => $event->everyFifteenMinutes() };
        }

        if ($this->getConfig('enable_database_backup', false)) {
            $time = $this->validBackupTime((string) $this->getConfig('database_backup_time', '03:30'));
            $schedule->call(fn () => $this->sendDatabaseBackup())
                ->name('telegram-database-backup')
                ->dailyAt($time)
                ->onOneServer()
                ->withoutOverlapping(180);
        }
    }

    public function handleMessage(bool $handled, array $data): bool
    {
        [$msg] = $data;
        if ($handled) return true;
        try {
            if ($msg->message_type === 'callback_query') return $this->handleCallback($msg);
            if ($msg->message_type === 'reply_message') return $this->handleReplyMessage($msg);
            return $this->handleCommandMessage($msg);
        } catch (\Throwable $e) {
            Log::error('Telegram command failed', ['chat_id' => $msg->chat_id ?? null, 'error' => $e->getMessage()]);
            $this->sendMessage($msg, $this->text('busy', $this->localeForMessage($msg)));
            return true;
        }
    }

    protected function handleCommandMessage(object $msg): bool
    {
        $buttonCommands = [];
        foreach (['vi-VN', 'en-US', 'zh-CN'] as $locale) {
            $buttonCommands[$this->text('button_traffic', $locale)] = '/traffic';
            $buttonCommands[$this->text('button_url', $locale)] = '/getlatesturl';
            $buttonCommands[$this->text('button_nodes', $locale)] = '/nodes';
            $buttonCommands[$this->text('button_reseller', $locale)] = '/reseller';
            $buttonCommands[$this->text('button_cancel', $locale)] = '/cancel';
        }
        if (isset($buttonCommands[$msg->text])) $msg->command = $buttonCommands[$msg->text];
        if (isset($this->commands['commands'][$msg->command])) {
            call_user_func($this->commands['commands'][$msg->command], $msg);
            return true;
        }
        if ($this->resellerState($msg)) {
            $this->handleResellerInput($msg);
            return true;
        }
        return false;
    }

    protected function handleReplyMessage(object $msg): bool
    {
        foreach ($this->commands['replies'] ?? [] as $regex => $handler) {
            if (preg_match($regex, $msg->reply_text, $matches)) {
                call_user_func($handler, $msg, $matches);
                return true;
            }
        }
        return false;
    }

    protected function handleCallback(object $msg): bool
    {
        $this->telegramService->answerCallbackQuery($msg->callback_query_id);
        if ($msg->command === 'reseller:new') return $this->startReseller($msg);
        if (str_starts_with($msg->command, 'reseller:plan:')) return $this->selectResellerPlan($msg, (int) substr($msg->command, 16));
        if (str_starts_with($msg->command, 'reseller:period:')) return $this->selectResellerPeriod($msg, substr($msg->command, 18));
        if ($msg->command === 'action:traffic') { $this->handleTrafficCommand($msg); return true; }
        if ($msg->command === 'action:url') { $this->handleGetLatestUrlCommand($msg); return true; }
        if ($msg->command === 'action:nodes') { $this->handleNodesCommand($msg); return true; }
        if ($msg->command === 'action:cancel') { $this->handleCancelCommand($msg); return true; }
        return false;
    }

    public function handleStartCommand(object $msg): void
    {
        $locale = $this->localeForMessage($msg);
        $user = $this->boundUser($msg, false);
        $body = $this->text('welcome', $locale) . "\n\n" . ($user
            ? $this->text('bound', $locale, ['email' => $user->email])
            : $this->text('not_bound', $locale));
        $buttons = [[$this->text('button_traffic', $locale), $this->text('button_url', $locale)]];
        if ($this->isOperator($msg)) {
            $buttons[] = [$this->text('button_nodes', $locale), $this->text('button_reseller', $locale)];
        } elseif ($this->isReseller($msg)) {
            $buttons[] = [$this->text('button_reseller', $locale)];
        }
        $this->sendMessage($msg, $body, ['keyboard' => $buttons, 'resize_keyboard' => true]);
    }

    public function handleBindCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        $locale = $this->localeForMessage($msg);
        $url = $msg->args[0] ?? '';
        if ($url === '') { $this->sendMessage($msg, $this->text('bad_bind', $locale)); return; }
        $token = $this->extractTokenFromUrl($url);
        if (!$token) { $this->sendMessage($msg, $this->text('invalid_url', $locale)); return; }
        $user = User::where('token', $token)->first();
        if (!$user) { $this->sendMessage($msg, $this->text('user_missing', $locale)); return; }
        if ($user->telegram_id && (string) $user->telegram_id !== (string) $this->actorId($msg)) {
            $this->sendMessage($msg, $this->text('already_bound', $locale)); return;
        }
        $user->telegram_id = $this->actorId($msg);
        if (!$user->save()) { $this->sendMessage($msg, $this->text('bind_failed', $locale)); return; }
        HookManager::call('user.telegram.bind.after', [$user]);
        $this->sendMessage($msg, $this->text('bind_ok', $locale));
    }

    public function handleTrafficCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        $user = $this->boundUser($msg);
        if (!$user) return;
        $used = ($user->u ?? 0) + ($user->d ?? 0); $total = $user->transfer_enable ?? 0;
        $this->sendMessage($msg, $this->text('traffic', $this->localeForUser($user), [
            'used' => $this->gb($used), 'total' => $this->gb($total), 'remaining' => $this->gb(max(0, $total - $used)),
            'percent' => number_format($total > 0 ? $used / $total * 100 : 0, 2),
        ]));
    }

    public function handleGetLatestUrlCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        $user = $this->boundUser($msg); if (!$user) return;
        $this->sendMessage($msg, $this->text('url', $this->localeForUser($user), ['url' => Helper::getSubscribeUrl($user->token)]));
    }

    public function handleUnbindCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        $user = $this->boundUser($msg); if (!$user) return;
        $locale = $this->localeForUser($user); $user->telegram_id = null;
        $this->sendMessage($msg, $this->text($user->save() ? 'unbind_ok' : 'unbind_failed', $locale));
    }

    public function handleNodesCommand(object $msg): void
    {
        if (!$this->isOperator($msg)) { $this->sendMessage($msg, $this->text('forbidden', $this->localeForMessage($msg))); return; }
        $this->sendMessage($msg, $this->nodeReport($this->localeForMessage($msg)));
    }

    public function handleSetReportGroupCommand(object $msg): void
    {
        $locale = $this->localeForMessage($msg);
        if (!$this->isOperator($msg)) { $this->sendMessage($msg, $this->text('forbidden', $locale)); return; }
        if ($msg->is_private) { $this->sendMessage($msg, $this->text('report_group_only', $locale)); return; }
        admin_setting(['telegram_node_report_chat_id' => (string) $msg->chat_id]);
        $this->sendMessage($msg, $this->text('report_group_ok', $locale));
    }

    public function handleSetBackupChatCommand(object $msg): void
    {
        $locale = $this->localeForMessage($msg);
        if (!$this->isAdmin($msg)) { $this->sendMessage($msg, $this->text('forbidden', $locale)); return; }
        if (!$msg->is_private) { $this->sendMessage($msg, $this->text('backup_private', $locale)); return; }
        admin_setting(['telegram_database_backup_chat_id' => (string) $msg->chat_id]);
        $this->sendMessage($msg, $this->text('backup_chat_ok', $locale));
    }

    public function handleBackupDatabaseCommand(object $msg): void
    {
        $locale = $this->localeForMessage($msg);
        if (!$this->isAdmin($msg)) { $this->sendMessage($msg, $this->text('forbidden', $locale)); return; }
        if (!$msg->is_private) { $this->sendMessage($msg, $this->text('backup_private', $locale)); return; }
        if (!$this->validBackupPassword()) { $this->sendMessage($msg, $this->text('backup_config_invalid', $locale)); return; }

        admin_setting(['telegram_database_backup_chat_id' => (string) $msg->chat_id]);
        $this->sendMessage($msg, $this->text('backup_started', $locale));
        $this->sendDatabaseBackup((int) $msg->chat_id, $locale);
    }

    public function sendScheduledNodeReport(): void
    {
        $chatId = (int) admin_setting('telegram_node_report_chat_id', 0);
        if ($chatId) $this->telegramService->sendMessage($chatId, $this->nodeReport('vi-VN'));
    }

    public function sendDatabaseBackup(?int $targetChatId = null, string $locale = 'vi-VN'): void
    {
        $chatId = $targetChatId ?: (int) ($this->getConfig('database_backup_chat_id', 0)
            ?: admin_setting('telegram_database_backup_chat_id', 0));
        if ($chatId <= 0) {
            Log::warning('Telegram database backup skipped: no valid private destination chat configured');
            return;
        }

        $password = $this->backupPassword();
        if (strlen($password) < 16) {
            Log::warning('Telegram database backup skipped: encryption password is missing or too short');
            $this->telegramService->sendMessage($chatId, $this->text('backup_config_invalid', $locale));
            return;
        }

        $lock = Cache::lock('telegram:database-backup', 10800);
        if (!$lock->get()) return;
        $backupPath = null;

        try {
            $backupPath = app(EncryptedDatabaseBackupService::class)->create($password);
            $size = filesize($backupPath);
            $maxBytes = max(1, (int) $this->getConfig('database_backup_max_mb', 45)) * 1024 * 1024;
            if ($size === false || $size > $maxBytes) throw new \RuntimeException('Encrypted backup exceeds configured Telegram upload limit.');

            $this->telegramService->sendDocument(
                $chatId,
                $backupPath,
                "🔐 XBoard database backup\n" . now()->format('Y-m-d H:i:s T') . "\nAES-256-GCM"
            );
            Log::notice('Encrypted database backup sent to Telegram', ['chat_id' => $chatId, 'size' => $size]);
        } catch (\Throwable $e) {
            Log::error('Telegram database backup failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            try { $this->telegramService->sendMessage($chatId, $this->text('backup_failed', $locale)); } catch (\Throwable) {}
        } finally {
            if ($backupPath) @unlink($backupPath);
            $lock->release();
        }
    }

    public function handleResellerCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        if (!$this->isReseller($msg)) { $this->sendMessage($msg, $this->text('forbidden', $this->localeForMessage($msg))); return; }
        $this->sendMessage($msg, $this->text('reseller_intro', $this->localeForMessage($msg)), [
            'inline_keyboard' => [[['text' => $this->text('button_create', $this->localeForMessage($msg)), 'callback_data' => 'reseller:new']], [['text' => $this->text('button_cancel', $this->localeForMessage($msg)), 'callback_data' => 'action:cancel']]],
        ]);
    }

    protected function startReseller(object $msg): bool
    {
        if (!$this->isReseller($msg) || !$this->privateChat($msg)) return true;
        $this->setResellerState($msg, ['step' => 'email']);
        $this->sendMessage($msg, $this->text('reseller_intro', $this->localeForMessage($msg))); return true;
    }

    protected function handleResellerInput(object $msg): void
    {
        if (!$this->isReseller($msg)) { $this->clearResellerState($msg); return; }
        $state = $this->resellerState($msg); $locale = $this->localeForMessage($msg); $value = trim((string) $msg->text);
        if (($state['step'] ?? '') === 'email') {
            $email = strtolower($value);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->sendMessage($msg, $this->text('reseller_email_invalid', $locale)); return; }
            if (User::byEmail($email)->exists()) { $this->sendMessage($msg, $this->text('reseller_email_exists', $locale)); return; }
            $state = ['step' => 'plan', 'email' => $email]; $this->setResellerState($msg, $state);
            $plans = Plan::query()->where('show', true)->where('sell', true)->orderBy('sort')->get();
            if ($plans->isEmpty()) { $this->clearResellerState($msg); $this->sendMessage($msg, $this->text('reseller_no_plan', $locale)); return; }
            $keyboard = $plans->map(fn ($p) => [[ 'text' => $p->name, 'callback_data' => 'reseller:plan:' . $p->id ]])->all();
            $this->sendMessage($msg, $this->text('reseller_choose_plan', $locale, ['email' => $email]), ['inline_keyboard' => $keyboard]); return;
        }
        if (($state['step'] ?? '') === 'coupon') $this->completeResellerPurchase($msg, $state, $value);
    }

    protected function selectResellerPlan(object $msg, int $planId): bool
    {
        $state = $this->resellerState($msg); $plan = Plan::whereKey($planId)->where('show', true)->where('sell', true)->first();
        if (!$this->isReseller($msg) || !$plan || empty($state['email'])) return true;
        $periods = collect($plan->prices)->filter(fn ($price) => $price !== null)->keys();
        $state = array_merge($state, ['step' => 'period', 'plan_id' => $plan->id]); $this->setResellerState($msg, $state);
        $locale = $this->localeForMessage($msg);
        $keyboard = $periods->map(fn ($period) => [[ 'text' => $this->periodName($period, $locale), 'callback_data' => 'reseller:period:' . $period ]])->all();
        $this->sendMessage($msg, $this->text('reseller_choose_period', $this->localeForMessage($msg), ['plan' => $plan->name]), ['inline_keyboard' => $keyboard]); return true;
    }

    protected function selectResellerPeriod(object $msg, string $period): bool
    {
        $state = $this->resellerState($msg); $plan = Plan::find($state['plan_id'] ?? 0);
        if (!$this->isReseller($msg) || !$plan || !array_key_exists($period, $plan->prices ?? []) || $plan->prices[$period] === null) return true;
        $state = array_merge($state, ['step' => 'coupon', 'period' => $period]); $this->setResellerState($msg, $state);
        $this->sendMessage($msg, $this->text('reseller_coupon', $this->localeForMessage($msg))); return true;
    }

    protected function completeResellerPurchase(object $msg, array $state, string $couponCode): void
    {
        $locale = $this->localeForMessage($msg);
        $coupon = Coupon::where('code', $couponCode)->first();
        if (!$coupon || (int) $coupon->type !== 2 || (int) $coupon->value !== 100) {
            $this->sendMessage($msg, $this->text('reseller_coupon_invalid', $locale)); return;
        }
        try {
            $result = DB::transaction(function () use ($state, $couponCode, $msg) {
                if (User::byEmail($state['email'])->exists()) throw new \RuntimeException('email exists');
                $password = bin2hex(random_bytes(8)) . 'Aa1!';
                $user = app(UserService::class)->createUser(['email' => $state['email'], 'password' => $password]);
                $user->locale = $this->localeForMessage($msg); $user->saveOrFail();
                $plan = Plan::findOrFail($state['plan_id']);
                $order = OrderService::createFromRequest($user, $plan, $state['period'], $couponCode);
                if ((int) $order->total_amount !== 0 || (int) $order->discount_amount <= 0) throw new \RuntimeException('coupon did not fully discount order');
                if (!(new OrderService($order))->paid('telegram-reseller-' . $this->actorId($msg))) throw new \RuntimeException('order activation failed');
                Log::notice('Telegram reseller created customer', ['operator_telegram_id' => $this->actorId($msg), 'user_id' => $user->id, 'order_id' => $order->id, 'coupon_id' => $order->coupon_id]);
                return compact('user', 'plan', 'order', 'password');
            });
        } catch (\Throwable $e) {
            Log::warning('Telegram reseller purchase rejected', ['operator_telegram_id' => $this->actorId($msg), 'error' => $e->getMessage()]);
            $this->sendMessage($msg, $this->text('reseller_coupon_invalid', $locale)); return;
        }
        $this->clearResellerState($msg);
        $this->sendMessage($msg, $this->text('reseller_done', $locale, [
            'email' => $result['user']->email, 'password' => $result['password'], 'plan' => $result['plan']->name,
            'period' => $this->periodName($result['order']->period, $locale), 'url' => Helper::getSubscribeUrl($result['user']->token),
        ]));
    }

    public function handleCancelCommand(object $msg): void
    {
        $this->clearResellerState($msg); $this->sendMessage($msg, $this->text('cancelled', $this->localeForMessage($msg)));
    }

    public function sendOrderLifecycleNotify(Order $order): void
    {
        $order->loadMissing(['user', 'plan']); $user = $order->user;
        if (!$user?->telegram_id) return;
        $key = match ((int) $order->type) { Order::TYPE_RENEWAL => 'renewed', Order::TYPE_UPGRADE => 'upgraded', Order::TYPE_RESET_TRAFFIC => 'traffic_reset', default => 'purchased' };
        $expires = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '∞';
        $this->telegramService->sendMessage($user->telegram_id, $this->text($key, $this->localeForUser($user), ['plan' => $order->plan?->name ?? '-', 'expires' => $expires]));
    }

    public function sendSubscriptionResetNotify(array $payload): void
    {
        [$user, $url] = $payload;
        if ($user->telegram_id) $this->telegramService->sendMessage($user->telegram_id, $this->text('url_reset', $this->localeForUser($user), ['url' => $url]));
    }

    public function sendTrafficResetNotify(array $payload): void
    {
        [$user, $source] = $payload;
        if ($source === TrafficResetLog::SOURCE_ORDER || !$user->telegram_id) return;
        $this->telegramService->sendMessage($user->telegram_id, $this->text('traffic_reset', $this->localeForUser($user)));
    }

    public function sendPaymentNotify(Order $order): void
    {
        if (!$this->getConfig('enable_payment_notify', true) || !$order->payment) return;
        $this->telegramService->sendMessageWithAdminLocalized(function (User $admin) use ($order) {
            $values = [$order->total_amount / 100, Helper::escapeMarkdown($order->payment->payment), Helper::escapeMarkdown($order->payment->name), $order->trade_no];
            return match ($this->language((string) ($admin->locale ?? 'vi-VN'))) {
                'vi' => sprintf("💰 Thanh toán thành công %.2f CNY\nCổng: %s\nKênh: %s\nĐơn: `%s`", ...$values),
                'zh' => sprintf("💰 支付成功 %.2f CNY\n网关：%s\n通道：%s\n订单：`%s`", ...$values),
                default => sprintf("💰 Payment received %.2f CNY\nGateway: %s\nChannel: %s\nOrder: `%s`", ...$values),
            };
        }, true);
    }

    public function sendTicketNotify(Ticket $ticket): void
    {
        if (!$this->getConfig('enable_ticket_notify', true)) return;
        $message = $ticket->messages()->latest()->first(); $user = User::find($ticket->user_id); if (!$user || !$message) return;
        $this->telegramService->sendMessageWithAdminLocalized(function (User $admin) use ($ticket, $user, $message) {
            $subject = Helper::escapeMarkdown($ticket->subject);
            $body = Helper::escapeMarkdown($message->message);
            return match ($this->language((string) ($admin->locale ?? 'vi-VN'))) {
                'vi' => "📮 *Ticket #{$ticket->id}*\n📧 `{$user->email}`\n📝 *Chủ đề*: `{$subject}`\n💬 *Nội dung*: `{$body}`",
                'zh' => "📮 *工单 #{$ticket->id}*\n📧 `{$user->email}`\n📝 *主题*：`{$subject}`\n💬 *内容*：`{$body}`",
                default => "📮 *Ticket #{$ticket->id}*\n📧 `{$user->email}`\n📝 *Subject*: `{$subject}`\n💬 *Message*: `{$body}`",
            };
        }, true);
    }

    public function handleTicketReply(object $msg, array $matches): void
    {
        $actor = $this->operatorUser($msg);
        if (!$actor || (!$actor->is_admin && !$actor->is_staff)) { $this->sendMessage($msg, $this->text('forbidden', $this->localeForMessage($msg))); return; }
        $ticketId = (int) ($matches[1] ?? 0); if (!$ticketId || !Ticket::find($ticketId)) return;
        (new TicketService())->replyByAdmin($ticketId, $msg->text, $actor->id);
        $this->sendMessage($msg, $this->text('ticket_replied', $this->localeForMessage($msg), ['id' => $ticketId]));
    }

    public function handleUnknownCommand(array $data): void
    {
        [$msg] = $data; if ($msg->message_type === 'message') $this->sendMessage($msg, $this->text('unknown', $this->localeForMessage($msg)));
    }

    public function handleError(array $data): void
    {
        [$msg, $e] = $data; Log::error('Telegram message handler error', ['chat_id' => $msg->chat_id ?? null, 'error' => $e->getMessage()]);
    }

    public function addBotCommands(array $commands): array
    {
        foreach ($this->commandConfigs as $command => $config) $commands[] = ['command' => ltrim($command, '/'), 'description' => $config['description']];
        return $commands;
    }

    public function addLocalizedBotCommands(array $localized): array
    {
        $descriptions = [
            'vi' => ['start' => 'Mở menu chính', 'menu' => 'Mở menu chính', 'bind' => 'Liên kết tài khoản', 'traffic' => 'Xem lưu lượng', 'getlatesturl' => 'Lấy liên kết đăng ký', 'unbind' => 'Hủy liên kết tài khoản', 'nodes' => 'Xem người dùng online theo node', 'setreportgroup' => 'Đặt nhóm nhận báo cáo node', 'setbackupchat' => 'Đặt chat nhận backup database', 'backupdb' => 'Backup database ngay', 'reseller' => 'Tạo tài khoản khách hàng', 'cancel' => 'Hủy thao tác hiện tại'],
            'en' => ['start' => 'Open the main menu', 'menu' => 'Open the main menu', 'bind' => 'Link an account', 'traffic' => 'View traffic', 'getlatesturl' => 'Get subscription link', 'unbind' => 'Unlink the account', 'nodes' => 'View online users by node', 'setreportgroup' => 'Set the node report group', 'setbackupchat' => 'Set the database backup chat', 'backupdb' => 'Back up the database now', 'reseller' => 'Create a customer account', 'cancel' => 'Cancel the current action'],
            'zh' => ['start' => '打开主菜单', 'menu' => '打开主菜单', 'bind' => '绑定账号', 'traffic' => '查看流量', 'getlatesturl' => '获取订阅链接', 'unbind' => '解绑账号', 'nodes' => '查看各节点在线用户', 'setreportgroup' => '设置节点报告群组', 'setbackupchat' => '设置数据库备份私聊', 'backupdb' => '立即备份数据库', 'reseller' => '创建客户账号', 'cancel' => '取消当前操作'],
        ];
        foreach ($descriptions as $language => $items) {
            $localized[$language] = collect($items)->map(
                fn ($description, $command) => ['command' => $command, 'description' => $description]
            )->values()->all();
        }
        return $localized;
    }

    protected function sendMessage(object $msg, string $message, array $replyMarkup = []): void
    {
        $this->telegramService->sendMessage($msg->chat_id, $message, 'markdown', $replyMarkup);
    }

    protected function privateChat(object $msg): bool
    {
        if ($msg->is_private) return true;
        $this->sendMessage($msg, $this->text('private', $this->localeForMessage($msg))); return false;
    }

    protected function boundUser(object $msg, bool $notify = true): ?User
    {
        $user = User::where('telegram_id', $this->actorId($msg))->first();
        if (!$user && $notify) $this->sendMessage($msg, $this->text('bind_first', $this->localeForMessage($msg)));
        return $user;
    }

    protected function operatorUser(object $msg): ?User { return $this->boundUser($msg, false); }
    protected function isOperator(object $msg): bool { $u = $this->operatorUser($msg); return (bool) ($u && ($u->is_admin || $u->is_staff)); }
    protected function isAdmin(object $msg): bool { $u = $this->operatorUser($msg); return (bool) ($u && $u->is_admin); }
    protected function isReseller(object $msg): bool
    {
        if (!$this->getConfig('enable_reseller_bot', false)) return false;
        if ($this->isOperator($msg)) return true;
        $ids = array_filter(array_map('trim', explode(',', (string) $this->getConfig('reseller_telegram_ids', ''))));
        return in_array((string) $this->actorId($msg), $ids, true);
    }

    protected function actorId(object $msg): int { return (int) ($msg->from_id ?? $msg->chat_id); }
    protected function resellerKey(object $msg): string { return 'telegram_reseller_state:' . $this->actorId($msg); }
    protected function resellerState(object $msg): ?array { $state = Cache::get($this->resellerKey($msg)); return is_array($state) ? $state : null; }
    protected function setResellerState(object $msg, array $state): void { Cache::put($this->resellerKey($msg), $state, now()->addMinutes(15)); }
    protected function clearResellerState(object $msg): void { Cache::forget($this->resellerKey($msg)); }

    protected function backupPassword(): string
    {
        return (string) (config('services.telegram.database_backup_password') ?: $this->getConfig('database_backup_password', ''));
    }

    protected function validBackupPassword(): bool { return strlen($this->backupPassword()) >= 16; }

    protected function validBackupTime(string $time): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) return '03:30';
        return $time;
    }

    protected function localeForMessage(object $msg): string
    {
        $user = $this->boundUser($msg, false); return $user ? $this->localeForUser($user) : (string) ($msg->language_code ?? 'vi-VN');
    }
    protected function localeForUser(User $user): string { return (string) ($user->locale ?: 'vi-VN'); }
    protected function language(string $locale): string { $base = strtolower(explode('-', str_replace('_', '-', $locale))[0]); return in_array($base, ['vi', 'zh'], true) ? $base : 'en'; }
    protected function text(string $key, string $locale, array $replace = []): string
    {
        $text = $this->messages[$this->language($locale)][$key] ?? $this->messages['en'][$key] ?? $key;
        foreach ($replace as $name => $value) $text = str_replace(':' . $name, (string) $value, $text);
        return $text;
    }

    protected function nodeReport(string $locale): string
    {
        $lines = [$this->text('nodes_title', $locale), ''];
        foreach (Server::all()->filter(fn ($server) => !$server->parent_id) as $server) {
            $lines[] = $this->text('node_line', $locale, ['state' => $server->is_online ? '🟢' : '🔴', 'name' => $server->name, 'type' => strtoupper($server->type), 'online' => (int) $server->online]);
        }
        return implode("\n", $lines);
    }

    protected function extractTokenFromUrl(string $url): ?string
    {
        $parts = parse_url($url); if (!$parts) return null;
        if (isset($parts['query'])) { parse_str($parts['query'], $query); if (!empty($query['token'])) return (string) $query['token']; }
        $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/')); $token = end($segments);
        return is_string($token) && $token !== '' ? $token : null;
    }

    protected function gb(float|int $bytes): string { return number_format(Helper::transferToGB($bytes), 2, '.', ''); }
    protected function periodName(string $period, string $locale = 'vi-VN'): string
    {
        $language = $this->language($locale);
        $names = [
            'vi' => ['monthly' => 'Hàng tháng', 'quarterly' => '3 tháng', 'half_yearly' => '6 tháng', 'yearly' => 'Hàng năm', 'two_yearly' => '2 năm', 'three_yearly' => '3 năm', 'onetime' => 'Một lần', 'reset_traffic' => 'Đặt lại lưu lượng'],
            'en' => ['monthly' => 'Monthly', 'quarterly' => '3 months', 'half_yearly' => '6 months', 'yearly' => 'Yearly', 'two_yearly' => '2 years', 'three_yearly' => '3 years', 'onetime' => 'One-time', 'reset_traffic' => 'Traffic reset'],
            'zh' => ['monthly' => '月付', 'quarterly' => '季付', 'half_yearly' => '半年付', 'yearly' => '年付', 'two_yearly' => '两年付', 'three_yearly' => '三年付', 'onetime' => '一次性', 'reset_traffic' => '重置流量'],
        ];
        return $names[$language][$period] ?? $period;
    }
}
