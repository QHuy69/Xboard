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

    private const SUPPORTED_LOCALES = ['vi', 'en', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fa', 'ru'];

    protected array $commandConfigs = [
        '/start' => ['handler' => 'handleStartCommand'],
        '/menu' => ['handler' => 'handleStartCommand'],
        '/bind' => ['handler' => 'handleBindCommand'],
        '/traffic' => ['handler' => 'handleTrafficCommand'],
        '/getlatesturl' => ['handler' => 'handleGetLatestUrlCommand'],
        '/unbind' => ['handler' => 'handleUnbindCommand'],
        '/nodes' => ['handler' => 'handleNodesCommand'],
        '/setreportgroup' => ['handler' => 'handleSetReportGroupCommand'],
        '/setbackupchat' => ['handler' => 'handleSetBackupChatCommand'],
        '/backupdb' => ['handler' => 'handleBackupDatabaseCommand'],
        '/reseller' => ['handler' => 'handleResellerCommand'],
        '/cancel' => ['handler' => 'handleCancelCommand'],
    ];

    /** @var array<string, array{messages: array<string, string>, commands: array<string, string>, periods: array<string, string>}> */
    private array $catalogs = [];

    public function boot(): void
    {
        $this->telegramService = new TelegramService();
        foreach ($this->commandConfigs as $command => $config) {
            $this->commands['commands'][$command] = [$this, $config['handler']];
        }
        $this->commands['replies']['/(?:ticket|工单|工單|チケット|티켓|تیکت|тикет).*?#?\s*(\d+)/iu'] = [$this, 'handleTicketReply'];
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
            // Laravel requires named callback events before overlap and
            // one-server guards can be attached. Without the name, enabling
            // reports throws during schedule registration and silently drops
            // the Telegram task in PluginManager's isolation boundary.
            $event = $schedule->call(fn () => $this->sendScheduledNodeReport())
                ->name('telegram-node-group-report')
                ->onOneServer()
                ->withoutOverlapping(10);
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
        foreach (self::SUPPORTED_LOCALES as $locale) {
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
        admin_setting([
            'telegram_node_report_chat_id' => (string) $msg->chat_id,
            'telegram_node_report_locale' => $this->language($locale),
        ]);
        $this->sendMessage($msg, $this->text('report_group_ok', $locale));
    }

    public function handleSetBackupChatCommand(object $msg): void
    {
        $locale = $this->localeForMessage($msg);
        if (!$this->isAdmin($msg)) { $this->sendMessage($msg, $this->text('forbidden', $locale)); return; }
        if (!$msg->is_private) { $this->sendMessage($msg, $this->text('backup_private', $locale)); return; }
        admin_setting([
            'telegram_database_backup_chat_id' => (string) $msg->chat_id,
            'telegram_database_backup_locale' => $this->language($locale),
        ]);
        $this->sendMessage($msg, $this->text('backup_chat_ok', $locale));
    }

    public function handleBackupDatabaseCommand(object $msg): void
    {
        $locale = $this->localeForMessage($msg);
        if (!$this->isAdmin($msg)) { $this->sendMessage($msg, $this->text('forbidden', $locale)); return; }
        if (!$msg->is_private) { $this->sendMessage($msg, $this->text('backup_private', $locale)); return; }
        if (!$this->validBackupPassword()) { $this->sendMessage($msg, $this->text('backup_config_invalid', $locale)); return; }

        admin_setting([
            'telegram_database_backup_chat_id' => (string) $msg->chat_id,
            'telegram_database_backup_locale' => $this->language($locale),
        ]);
        $this->sendMessage($msg, $this->text('backup_started', $locale));
        $this->sendDatabaseBackup((int) $msg->chat_id, $locale);
    }

    public function sendScheduledNodeReport(): void
    {
        $chatId = (int) admin_setting('telegram_node_report_chat_id', 0);
        $locale = (string) admin_setting('telegram_node_report_locale', 'vi');
        if ($chatId) $this->telegramService->sendMessage($chatId, $this->nodeReport($locale));
    }

    public function sendDatabaseBackup(?int $targetChatId = null, string $locale = ''): void
    {
        $locale = $locale !== ''
            ? $locale
            : (string) admin_setting('telegram_database_backup_locale', 'vi');
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

            $this->telegramService->sendDocument($chatId, $backupPath, $this->text('backup_caption', $locale, [
                'time' => now()->format('Y-m-d H:i:s T'),
                'cipher' => 'AES-256-GCM',
            ]));
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
                $user->locale = $this->canonicalUserLocale($this->localeForMessage($msg)); $user->saveOrFail();
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
        $locale = $this->localeForUser($user);
        $expires = $user->expired_at
            ? date('Y-m-d H:i:s', $user->expired_at)
            : $this->text('expires_never', $locale);
        $this->telegramService->sendMessage($user->telegram_id, $this->text($key, $locale, ['plan' => $order->plan?->name ?? '-', 'expires' => $expires]));
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
            return $this->text('payment_received', $this->localeForUser($admin), [
                'amount' => number_format($order->total_amount / 100, 2, '.', ''),
                'gateway' => Helper::escapeMarkdown($order->payment->payment),
                'channel' => Helper::escapeMarkdown($order->payment->name),
                'order' => $order->trade_no,
            ]);
        }, true);
    }

    public function sendTicketNotify(Ticket $ticket): void
    {
        if (!$this->getConfig('enable_ticket_notify', true)) return;
        $message = $ticket->messages()->latest()->first(); $user = User::find($ticket->user_id); if (!$user || !$message) return;
        $this->telegramService->sendMessageWithAdminLocalized(function (User $admin) use ($ticket, $user, $message) {
            $subject = Helper::escapeMarkdown($ticket->subject);
            $body = Helper::escapeMarkdown($message->message);
            return $this->text('ticket_notify', $this->localeForUser($admin), [
                'id' => $ticket->id,
                'email' => $user->email,
                'subject' => $subject,
                'message' => $body,
            ]);
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
        $descriptions = $this->catalog('en')['commands'];
        foreach ($this->commandConfigs as $command => $_config) {
            $name = ltrim($command, '/');
            $commands[] = ['command' => $name, 'description' => $descriptions[$name]];
        }
        return $commands;
    }

    public function addLocalizedBotCommands(array $localized): array
    {
        // Telegram accepts ISO 639-1 codes here, so its command menu has one
        // generic Chinese slot. Bound users still receive zh-CN or zh-TW body
        // text and buttons according to their XBoard locale.
        $telegramLocales = [
            'vi' => 'vi',
            'en' => 'en',
            'zh' => 'zh-CN',
            'ja' => 'ja',
            'ko' => 'ko',
            'fa' => 'fa',
            'ru' => 'ru',
        ];
        foreach ($telegramLocales as $languageCode => $locale) {
            $localized[$languageCode] = collect($this->catalog($locale)['commands'])->map(
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
        $user = $this->boundUser($msg, false);
        return $user ? $this->localeForUser($user) : (string) ($msg->language_code ?? 'vi');
    }

    protected function localeForUser(User $user): string { return (string) ($user->locale ?: 'vi'); }

    /** Store only locale identifiers accepted by the user/admin APIs. */
    protected function canonicalUserLocale(string $locale): string
    {
        return match ($this->language($locale)) {
            'vi' => 'vi-VN',
            'zh-CN' => 'zh-CN',
            'zh-TW' => 'zh-TW',
            'ja' => 'ja-JP',
            'ko' => 'ko-KR',
            'fa' => 'fa-IR',
            'ru' => 'ru-RU',
            default => 'en-US',
        };
    }

    protected function language(string $locale): string
    {
        $locale = strtolower(trim(str_replace('_', '-', $locale)));
        if ($locale === '') return 'en';
        if (in_array($locale, ['zh-tw', 'zh-hk', 'zh-mo', 'zh-hant', 'zh-cht'], true)) return 'zh-TW';
        if ($locale === 'zh' || in_array($locale, ['zh-cn', 'zh-sg', 'zh-hans', 'zh-chs'], true)) return 'zh-CN';

        $base = explode('-', $locale)[0];
        return match ($base) {
            'vi' => 'vi',
            'ja', 'jp' => 'ja',
            'ko', 'kr' => 'ko',
            'fa', 'per' => 'fa',
            'ru' => 'ru',
            default => 'en',
        };
    }

    protected function text(string $key, string $locale, array $replace = []): string
    {
        $language = $this->language($locale);
        $text = $this->catalog($language)['messages'][$key] ?? $this->catalog('en')['messages'][$key] ?? $key;
        $tokens = [];
        foreach ($replace as $name => $value) {
            $value = (string) $value;
            // First-strong isolates keep URLs, emails, amounts and identifiers
            // readable inside Persian right-to-left sentences without changing
            // the Markdown delimiters supplied by each translation catalog.
            if ($language === 'fa') $value = "\u{2068}" . $value . "\u{2069}";
            $tokens[':' . $name] = $value;
        }
        return strtr($text, $tokens);
    }

    /** @return array{messages: array<string, string>, commands: array<string, string>, periods: array<string, string>} */
    protected function catalog(string $locale): array
    {
        $locale = $this->language($locale);
        if (isset($this->catalogs[$locale])) return $this->catalogs[$locale];

        $path = __DIR__ . '/locales/' . $locale . '.json';
        try {
            $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::error('Telegram locale catalog could not be loaded', ['locale' => $locale, 'error' => $e->getMessage()]);
            $catalog = ['messages' => [], 'commands' => [], 'periods' => []];
        }
        foreach (['messages', 'commands', 'periods'] as $section) {
            if (!isset($catalog[$section]) || !is_array($catalog[$section])) $catalog[$section] = [];
        }
        return $this->catalogs[$locale] = $catalog;
    }

    protected function nodeReport(string $locale): string
    {
        $lines = [$this->text('nodes_title', $locale), ''];
        foreach (Server::all()->filter(fn ($server) => !$server->parent_id) as $server) {
            $lines[] = $this->text('node_line', $locale, ['state' => $server->is_online ? '🟢' : '🔴', 'name' => $server->name, 'type' => strtoupper($server->type), 'online' => (int) $server->online]);
        }
        if (count($lines) === 2) $lines[] = $this->text('nodes_empty', $locale);
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
        return $this->catalog($locale)['periods'][$period] ?? $period;
    }
}
