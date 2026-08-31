<?php

namespace Plugin\Telegram;

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
use App\Services\TelegramBindingService;
use App\Services\TelegramResellerService;
use App\Services\TicketService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class Plugin extends AbstractPlugin
{
    protected array $commands = [];
    protected TelegramService $telegramService;
    protected TelegramBindingService $bindingService;
    protected TelegramResellerService $resellerService;

    private const SUPPORTED_LOCALES = ['vi', 'en', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fa', 'ru'];
    private const DELIVERY_TTL_SECONDS = 86400;
    private const RESELLER_STATE_TTL_SECONDS = 900;
    private const CONFIRMATION_TTL_SECONDS = 600;
    private const FLOW_NONCE_BYTES = 8;
    private const SUPPORT_TICKET_SUBJECT = '[Telegram reseller support]';
    // Dynamic Markdown escaping can expand adversarial input substantially;
    // 1,000 characters keeps relay + instructions below Telegram's limit.
    private const SUPPORT_MESSAGE_MAX_LENGTH = 1000;
    private const SUPPORT_RATE_ATTEMPTS = 6;
    private const SUPPORT_RATE_DECAY_SECONDS = 60;
    // Leave headroom for TelegramService's final Markdown underscore escaping.
    private const NODE_REPORT_MESSAGE_LIMIT = 2400;

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
        $this->bindingService = app(TelegramBindingService::class);
        $this->resellerService = app(TelegramResellerService::class);
        foreach ($this->commandConfigs as $command => $config) {
            $this->commands['commands'][$command] = [$this, $config['handler']];
        }
        $this->commands['replies']['/\nZG-SUPPORT-REF:\s*`?([a-f0-9]{80,1024})`?\s*$/u'] = [$this, 'handleSupportAdminReply'];
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
            $interval = $this->nodeReportInterval();
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

        $deliveryKey = $this->deliveryKey($msg);
        if ($deliveryKey !== null
            && !Cache::add($deliveryKey, 'processing', self::DELIVERY_TTL_SECONDS)) {
            // Telegram retries webhooks when it does not receive a timely 2xx.
            // A callback acknowledgement is safe to repeat; business work is
            // not. The first delivery owns the cached claim for one day.
            if (($msg->message_type ?? '') === 'callback_query'
                && isset($msg->callback_query_id)) {
                try {
                    $this->telegramService->answerCallbackQuery((string) $msg->callback_query_id);
                } catch (\Throwable) {
                }
            }
            return true;
        }

        try {
            $result = match ((string) ($msg->message_type ?? '')) {
                'callback_query' => $this->handleCallback($msg),
                'reply_message' => $this->handleReplyMessage($msg),
                default => $this->handleCommandMessage($msg),
            };
            if ($deliveryKey !== null) {
                if ($result) {
                    Cache::put($deliveryKey, 'done', self::DELIVERY_TTL_SECONDS);
                } else {
                    // Do not claim messages this plugin did not handle; another
                    // plugin may own them and Telegram may legitimately retry.
                    Cache::forget($deliveryKey);
                }
            }
            return $result;
        } catch (\Throwable $e) {
            if ($deliveryKey !== null) Cache::forget($deliveryKey);
            Log::error('Telegram command failed', array_filter([
                'action' => $this->logAction($msg),
                'operator_user_id' => $this->boundUserIdForLog($msg),
                'error_type' => $e::class,
            ], static fn ($value) => $value !== null));
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
        // A reseller may naturally use Telegram's Reply gesture while a
        // private coupon/support flow is active. Preserve that text input only
        // after the privileged reply mappings above have declined it.
        if ($this->resellerState($msg)) {
            $this->handleResellerInput($msg);
            return true;
        }
        return false;
    }

    protected function handleCallback(object $msg): bool
    {
        $this->telegramService->answerCallbackQuery($msg->callback_query_id);
        $command = (string) $msg->command;

        if ($command === 'action:menu') { $this->handleStartCommand($msg); return true; }
        if ($command === 'action:traffic') { $this->handleTrafficCommand($msg); return true; }
        if ($command === 'action:url') { $this->handleGetLatestUrlCommand($msg); return true; }
        if ($command === 'action:nodes') { $this->handleNodesCommand($msg); return true; }
        if ($command === 'action:unbind:confirm') return $this->confirmUnbind($msg);
        if (preg_match('/^action:unbind:yes:([a-f0-9]{16})$/', $command, $matches)) {
            return $this->completeUnbind($msg, $matches[1]);
        }
        if ($command === 'action:unbind:yes') return $this->rejectExpiredCallback($msg);
        if ($command === 'action:cancel') { $this->handleCancelCommand($msg); return true; }

        if ($command === 'reseller:menu') { $this->handleResellerCommand($msg); return true; }
        if ($command === 'reseller:new') return $this->startReseller($msg);
        if ($command === 'reseller:support:open') return $this->openResellerSupport($msg);
        if (preg_match('/^reseller:support:close:([a-f0-9]{16})$/', $command, $matches)) {
            return $this->closeResellerSupport($msg, $matches[1]);
        }
        if (preg_match('/^reseller:support:cancel:([a-f0-9]{16})$/', $command, $matches)) {
            return $this->cancelResellerSupport($msg, $matches[1]);
        }
        if (in_array($command, ['reseller:support:close', 'reseller:support:cancel'], true)) {
            return $this->rejectExpiredCallback($msg);
        }
        if (preg_match('/^reseller:customers:([1-9][0-9]*)$/', $command, $matches)) {
            return $this->showResellerCustomers($msg, (int) $matches[1]);
        }
        if (preg_match('/^reseller:customer:([1-9][0-9]*)$/', $command, $matches)) {
            return $this->showResellerCustomer($msg, (int) $matches[1]);
        }
        if (preg_match('/^reseller:url:([1-9][0-9]*)$/', $command, $matches)) {
            return $this->showCustomerSubscription($msg, (int) $matches[1]);
        }
        if (preg_match('/^reseller:reset-confirm:([1-9][0-9]*)$/', $command, $matches)) {
            return $this->confirmCustomerSubscriptionReset($msg, (int) $matches[1]);
        }
        if (preg_match('/^reseller:reset:([1-9][0-9]*):([a-f0-9]{16})$/', $command, $matches)) {
            return $this->resetCustomerSubscription($msg, (int) $matches[1], $matches[2]);
        }
        if (preg_match('/^reseller:purchase:([1-9][0-9]*)$/', $command, $matches)) {
            return $this->startCustomerPurchase($msg, (int) $matches[1]);
        }
        if (preg_match('/^reseller:plan:([1-9][0-9]*):([a-f0-9]{16})$/', $command, $matches)) {
            return $this->selectResellerPlan($msg, (int) $matches[1], $matches[2]);
        }
        if (preg_match('/^reseller:period:([a-z_]+):([a-f0-9]{16})$/', $command, $matches)) {
            return $this->selectResellerPeriod($msg, $matches[1], $matches[2]);
        }
        if (preg_match('/^(?:reseller:(?:reset|plan):[1-9][0-9]*|reseller:period:[a-z_]+)$/', $command)) {
            return $this->rejectExpiredCallback($msg);
        }
        return false;
    }

    public function handleStartCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        $this->clearResellerState($msg);
        $this->clearConfirmation($msg, 'unbind');
        $locale = $this->localeForMessage($msg);
        $payload = trim((string) ($msg->args[0] ?? ''));
        if (str_starts_with($payload, 'bind_')) {
            $bound = $this->bindingService->consume($payload, $this->actorId($msg));
            if ($bound) {
                HookManager::call('user.telegram.bind.after', [$bound]);
                $locale = $this->localeForUser($bound);
                $this->sendMessage($msg, $this->text('bind_ok', $locale));
            } else {
                $this->sendMessage($msg, $this->text('bind_token_invalid', $locale));
            }
        }

        $user = $this->boundUser($msg, false);
        $body = $this->text('welcome', $locale) . "\n\n" . ($user
            ? $this->text('bound', $locale, ['email' => $user->email])
            : $this->text('not_bound', $locale));

        $buttons = [];
        if ($user) {
            $buttons[] = [
                ['text' => $this->text('button_traffic', $locale), 'callback_data' => 'action:traffic'],
                ['text' => $this->text('button_url', $locale), 'callback_data' => 'action:url'],
            ];
            $buttons[] = [[
                'text' => $this->text('button_unbind', $locale),
                'callback_data' => 'action:unbind:confirm',
            ]];
            if ($this->isOperator($msg)) {
                $buttons[] = [[
                    'text' => $this->text('button_nodes', $locale),
                    'callback_data' => 'action:nodes',
                ]];
            }
            if ($this->isReseller($msg)) {
                $buttons[] = [[
                    'text' => $this->text('button_reseller', $locale),
                    'callback_data' => 'reseller:menu',
                ]];
            }
        }
        $markup = $buttons === [] ? [] : ['inline_keyboard' => $buttons];
        $this->sendMessage($msg, $body, $markup);
    }

    public function handleBindCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        // Subscription URLs are credentials and must never be pasted into a
        // chat. Binding now starts only from the authenticated dashboard's
        // short-lived, one-time Telegram deep link.
        $this->sendMessage($msg, $this->text('bind_dashboard', $this->localeForMessage($msg)));
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
        $this->confirmUnbind($msg);
    }

    protected function confirmUnbind(object $msg): bool
    {
        if (!$this->privateChat($msg)) return true;
        $user = $this->boundUser($msg); if (!$user) return true;
        $locale = $this->localeForUser($user);
        $nonce = $this->newNonce();
        $this->setConfirmation($msg, 'unbind', [
            'nonce' => $nonce,
            'user_id' => (int) $user->id,
        ]);
        $this->sendMessage($msg, $this->text('unbind_confirm', $locale), [
            'inline_keyboard' => [[
                ['text' => $this->text('button_confirm', $locale), 'callback_data' => 'action:unbind:yes:' . $nonce],
                ['text' => $this->text('button_cancel', $locale), 'callback_data' => 'action:menu'],
            ]],
        ]);
        return true;
    }

    protected function completeUnbind(object $msg, string $nonce): bool
    {
        if (!$this->privateChat($msg)) return true;
        $user = $this->boundUser($msg); if (!$user) return true;
        $locale = $this->localeForUser($user);

        $lock = Cache::lock($this->operationLockKey('unbind', (int) $user->id, $nonce), 15);
        if (!$lock->get()) {
            $this->sendMessage($msg, $this->text('busy', $locale));
            return true;
        }
        try {
            $confirmation = $this->consumeConfirmation($msg, 'unbind', $nonce);
            if (!$confirmation || (int) ($confirmation['user_id'] ?? 0) !== (int) $user->id) {
                $this->sendMessage($msg, $this->text('operation_expired', $locale));
                return true;
            }
            // Revoke every outstanding dashboard deep link before removing the
            // binding so a previously issued link cannot silently rebind it.
            $this->bindingService->revoke($user);
            $user->telegram_id = null;
            if (!$user->save()) {
                $this->sendMessage($msg, $this->text('unbind_failed', $locale));
                return true;
            }
            HookManager::call('user.telegram.unbind.after', [$user]);
            $this->sendMessage($msg, $this->text('unbind_ok', $locale));
            return true;
        } finally {
            $lock->release();
        }
    }

    public function handleNodesCommand(object $msg): void
    {
        if (!$this->isOperator($msg)) { $this->sendMessage($msg, $this->text('forbidden', $this->localeForMessage($msg))); return; }
        foreach ($this->nodeReportChunks($this->localeForMessage($msg)) as $chunk) {
            $this->sendMessage($msg, $chunk);
        }
    }

    public function handleSetReportGroupCommand(object $msg): void
    {
        $locale = $this->localeForMessage($msg);
        // This mutates one global delivery destination. Read-only node status
        // remains available to staff, but only a bound administrator may
        // redirect scheduled reports to a different Telegram group.
        if (!$this->isAdmin($msg)) { $this->sendMessage($msg, $this->text('forbidden', $locale)); return; }
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
        if (!$this->getConfig('enable_node_group_report', false)) return;
        $botEnabled = filter_var(
            admin_setting('telegram_bot_enable', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
        if (!$botEnabled) {
            Log::warning('Telegram node report skipped: Telegram bot is disabled');
            return;
        }
        if (trim((string) admin_setting('telegram_bot_token', '')) === '') {
            Log::warning('Telegram node report skipped: bot token is missing');
            return;
        }
        $chatId = $this->nodeReportChatId();
        if ($chatId === null) {
            Log::warning('Telegram node report skipped: no valid destination chat configured');
            return;
        }

        $interval = $this->nodeReportInterval();
        $lock = null;
        $locked = false;
        try {
            // Scheduler mutexes are the primary cross-replica guard. This
            // explicit lock plus interval-slot claim also protects direct or
            // accidentally duplicated scheduler invocation.
            $lock = Cache::lock('telegram:node-report:dispatch', 300);
            $locked = $lock->get();
            if (!$locked) return;
            $slot = intdiv(time(), $interval * 60);
            $claimKey = 'telegram:node-report:slot:' . $interval . ':' . $slot;
            if (!Cache::add($claimKey, 'processing', ($interval * 60) + 120)) return;

            if (!isset($this->telegramService)) $this->telegramService = new TelegramService();
            $chunks = $this->nodeReportChunks($this->nodeReportLocale());
            foreach ($chunks as $chunk) {
                $this->telegramService->sendMessage($chatId, $chunk, 'markdown');
            }
            Cache::put($claimKey, 'done', ($interval * 60) + 120);
        } catch (\Throwable $e) {
            // Keep the slot claim after a partial external send. Retrying the
            // same slot could duplicate the chunks Telegram already accepted.
            Log::error('Telegram scheduled node report failed', [
                'action' => 'node_report',
                'error_type' => $e::class,
            ]);
        } finally {
            if ($locked && $lock) {
                try { $lock->release(); } catch (\Throwable) {}
            }
        }
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
            Log::notice('Encrypted database backup sent to Telegram', ['size' => $size]);
        } catch (\Throwable $e) {
            Log::error('Telegram database backup failed', [
                'action' => 'database_backup',
                'error_type' => $e::class,
            ]);
            try { $this->telegramService->sendMessage($chatId, $this->text('backup_failed', $locale)); } catch (\Throwable) {}
        } finally {
            if ($backupPath) @unlink($backupPath);
            $lock->release();
        }
    }

    public function handleResellerCommand(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        $actor = $this->resellerActor($msg);
        if (!$actor) return;
        $this->clearResellerState($msg);
        $locale = $this->localeForUser($actor);
        $keyboard = [
            [['text' => $this->text('button_create', $locale), 'callback_data' => 'reseller:new']],
            [['text' => $this->text('button_customers', $locale), 'callback_data' => 'reseller:customers:1']],
        ];
        if ($this->supportChatId() !== null) {
            $keyboard[] = [[
                'text' => $this->text('button_support', $locale),
                'callback_data' => 'reseller:support:open',
            ]];
        }
        $keyboard[] = [[
            'text' => $this->text('button_back', $locale),
            'callback_data' => 'action:menu',
        ]];
        $this->sendMessage($msg, $this->text('reseller_intro', $locale), [
            'inline_keyboard' => $keyboard,
        ]);
    }

    protected function startReseller(object $msg): bool
    {
        if (!$this->privateChat($msg)) return true;
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $state = $this->beginResellerState($msg, $actor, ['step' => 'plan', 'mode' => 'create']);
        return $this->showResellerPlans($msg, $actor, $state);
    }

    protected function handleResellerInput(object $msg): void
    {
        if (!$this->privateChat($msg)) return;
        $actor = $this->resellerActor($msg, false);
        if (!$actor) { $this->clearResellerState($msg); return; }
        $state = $this->resellerStateForActor($msg, $actor);
        if (($state['step'] ?? '') === 'coupon') {
            $this->completeResellerPurchase($msg, $actor, $state, trim((string) $msg->text));
            return;
        }
        if (in_array($state['step'] ?? '', ['support_waiting', 'support_active'], true)) {
            $this->handleResellerSupportInput($msg, $actor, $state);
        }
    }

    protected function openResellerSupport(object $msg): bool
    {
        if (!$this->privateChat($msg)) return true;
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $locale = $this->localeForUser($actor);
        if ($this->supportChatId() === null) {
            $this->clearResellerState($msg);
            $this->sendMessage($msg, $this->text('support_unavailable', $locale));
            return true;
        }

        $ticket = $this->supportTicketForActor($actor);
        $state = $this->beginResellerState($msg, $actor, array_filter([
            'step' => $ticket ? 'support_active' : 'support_waiting',
            'mode' => 'support',
            'ticket_id' => $ticket ? (int) $ticket->id : null,
        ], static fn ($value) => $value !== null));
        $nonce = (string) $state['nonce'];
        $buttons = $ticket
            ? [[
                'text' => $this->text('button_close_support', $locale),
                'callback_data' => 'reseller:support:close:' . $nonce,
            ]]
            : [[
                'text' => $this->text('button_cancel_support', $locale),
                'callback_data' => 'reseller:support:cancel:' . $nonce,
            ]];
        $this->sendMessage(
            $msg,
            $this->text($ticket ? 'support_resumed' : 'support_intro', $locale),
            ['inline_keyboard' => [$buttons, [[
                'text' => $this->text('button_back', $locale),
                'callback_data' => 'reseller:menu',
            ]]]],
        );
        return true;
    }

    protected function handleResellerSupportInput(object $msg, User $actor, array $state): void
    {
        $locale = $this->localeForUser($actor);
        if (!$this->privateChat($msg)) return;
        // A support message must carry Telegram's stable message identifier so
        // handleMessage() can claim it before any ticket row is mutated.
        if ($this->deliveryKey($msg) === null) {
            $this->sendMessage($msg, $this->text('support_failed', $locale));
            return;
        }
        if ($this->supportChatId() === null) {
            $this->clearResellerState($msg);
            $this->sendMessage($msg, $this->text('support_unavailable', $locale));
            return;
        }
        $message = trim((string) ($msg->text ?? ''));
        if ($message === '' || mb_strlen($message) > self::SUPPORT_MESSAGE_MAX_LENGTH) {
            $this->sendMessage($msg, $this->text('support_message_invalid', $locale, [
                'limit' => self::SUPPORT_MESSAGE_MAX_LENGTH,
            ]));
            return;
        }
        $rateKey = 'telegram:support:user:' . (int) $actor->id;
        if (RateLimiter::tooManyAttempts($rateKey, self::SUPPORT_RATE_ATTEMPTS)) {
            $this->sendMessage($msg, $this->text('support_rate_limited', $locale));
            return;
        }
        RateLimiter::hit($rateKey, self::SUPPORT_RATE_DECAY_SECONDS);

        $nonce = (string) ($state['nonce'] ?? '');
        $lock = Cache::lock($this->operationLockKey('support_message', (int) $actor->id, $nonce), 20);
        if (!$lock->get()) {
            $this->sendMessage($msg, $this->text('busy', $locale));
            return;
        }
        try {
            $currentState = $this->resellerStateForActor($msg, $actor);
            if (!$currentState
                || !hash_equals((string) ($currentState['nonce'] ?? ''), $nonce)
                || !in_array($currentState['step'] ?? '', ['support_waiting', 'support_active'], true)) {
                $this->sendMessage($msg, $this->text('operation_expired', $locale));
                return;
            }

            $ticket = isset($currentState['ticket_id'])
                ? $this->supportTicketForActor($actor, (int) $currentState['ticket_id'])
                : $this->supportTicketForActor($actor);
            if (($currentState['step'] ?? '') === 'support_active' && !$ticket) {
                $this->clearResellerState($msg);
                $this->sendMessage($msg, $this->text('support_closed', $locale));
                return;
            }

            $hookName = '';
            try {
                if ($ticket) {
                    $ticketMessage = (new TicketService())->reply($ticket, $message, (int) $actor->id);
                    if (!$ticketMessage) throw new \RuntimeException('Support ticket reply failed');
                    $hookName = 'ticket.reply.user.after';
                } else {
                    $ticket = (new TicketService())->createTicket(
                        (int) $actor->id,
                        self::SUPPORT_TICKET_SUBJECT,
                        1,
                        $message,
                    );
                    $hookName = 'ticket.create.after';
                }
            } catch (\Throwable $e) {
                Log::warning('Telegram reseller support message rejected', [
                    'action' => 'support_message',
                    'operator_user_id' => (int) $actor->id,
                    'error_type' => $e::class,
                ]);
                try {
                    $this->sendMessage($msg, $this->text('support_failed', $locale));
                } catch (\Throwable $notifyError) {
                    Log::error('Telegram reseller support failure notice could not be delivered', [
                        'action' => 'support_failure_notice',
                        'operator_user_id' => (int) $actor->id,
                        'error_type' => $notifyError::class,
                    ]);
                }
                return;
            }

            // TicketService has already committed. A notification listener is
            // external to that transaction and must never make Telegram retry
            // the durable message or report it as unsaved.
            try {
                HookManager::call($hookName, $ticket);
            } catch (\Throwable $e) {
                Log::error('Telegram reseller support post-save hook failed', [
                    'action' => 'support_post_save_hook',
                    'operator_user_id' => (int) $actor->id,
                    'ticket_id' => (int) $ticket->id,
                    'error_type' => $e::class,
                ]);
            }

            $currentState['step'] = 'support_active';
            $currentState['ticket_id'] = (int) $ticket->id;
            $stateStored = true;
            try {
                $this->setResellerState($msg, $currentState);
            } catch (\Throwable $e) {
                $stateStored = false;
                Log::error('Telegram reseller support state refresh failed', [
                    'action' => 'support_state_refresh',
                    'operator_user_id' => (int) $actor->id,
                    'ticket_id' => (int) $ticket->id,
                    'error_type' => $e::class,
                ]);
            }

            $nextButton = $stateStored
                ? [
                    'text' => $this->text('button_close_support', $locale),
                    'callback_data' => 'reseller:support:close:' . $nonce,
                ]
                : [
                    'text' => $this->text('button_support', $locale),
                    'callback_data' => 'reseller:support:open',
                ];
            try {
                $this->sendMessage($msg, $this->text('support_sent', $locale), [
                    'inline_keyboard' => [[$nextButton], [[
                        'text' => $this->text('button_back', $locale),
                        'callback_data' => 'reseller:menu',
                    ]]],
                ]);
            } catch (\Throwable $e) {
                // The durable ticket message is the source of truth. Swallow
                // acknowledgement failures so a webhook retry cannot append
                // the same support message again.
                Log::error('Telegram reseller support acknowledgement failed', [
                    'action' => 'support_user_ack',
                    'operator_user_id' => (int) $actor->id,
                    'ticket_id' => (int) $ticket->id,
                    'error_type' => $e::class,
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    protected function closeResellerSupport(object $msg, string $nonce): bool
    {
        if (!$this->privateChat($msg)) return true;
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $locale = $this->localeForUser($actor);
        $state = $this->resellerStateForActor($msg, $actor);
        if (!$this->stateMatches($state, $nonce, 'support_active')) {
            $this->sendMessage($msg, $this->text('operation_expired', $locale));
            return true;
        }

        $lock = Cache::lock($this->operationLockKey('support_close', (int) $actor->id, $nonce), 20);
        if (!$lock->get()) {
            $this->sendMessage($msg, $this->text('busy', $locale));
            return true;
        }
        try {
            $currentState = $this->resellerStateForActor($msg, $actor);
            if (!$this->stateMatches($currentState, $nonce, 'support_active')) {
                $this->sendMessage($msg, $this->text('operation_expired', $locale));
                return true;
            }
            $ticketId = (int) ($currentState['ticket_id'] ?? 0);
            DB::transaction(function () use ($actor, $ticketId): void {
                $ticket = Ticket::query()
                    ->whereKey($ticketId)
                    ->where('user_id', $actor->id)
                    ->where('subject', self::SUPPORT_TICKET_SUBJECT)
                    ->lockForUpdate()
                    ->first();
                if (!$ticket) return;
                $ticket->status = Ticket::STATUS_CLOSED;
                $ticket->saveOrFail();
            }, 3);
            $this->clearResellerState($msg);
        } finally {
            $lock->release();
        }
        $this->sendMessage($msg, $this->text('support_closed', $locale), [
            'inline_keyboard' => [[[
                'text' => $this->text('button_reseller', $locale),
                'callback_data' => 'reseller:menu',
            ]]],
        ]);
        return true;
    }

    protected function cancelResellerSupport(object $msg, string $nonce): bool
    {
        if (!$this->privateChat($msg)) return true;
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $locale = $this->localeForUser($actor);
        $state = $this->resellerStateForActor($msg, $actor);
        if (!$this->stateMatches($state, $nonce, 'support_waiting')) {
            $this->sendMessage($msg, $this->text('operation_expired', $locale));
            return true;
        }
        $this->clearResellerState($msg);
        $this->sendMessage($msg, $this->text('support_cancelled', $locale), [
            'inline_keyboard' => [[[
                'text' => $this->text('button_reseller', $locale),
                'callback_data' => 'reseller:menu',
            ]]],
        ]);
        return true;
    }

    protected function showResellerPlans(object $msg, User $actor, array $state): bool
    {
        $locale = $this->localeForUser($actor);
        $plans = $this->resellerService->availablePlans();
        if ($plans->isEmpty()) {
            $this->clearResellerState($msg);
            $this->sendMessage($msg, $this->text('reseller_no_plan', $locale));
            return true;
        }
        $nonce = (string) ($state['nonce'] ?? '');
        $keyboard = $plans->map(fn (Plan $plan) => [[
            'text' => (string) $plan->name,
            'callback_data' => 'reseller:plan:' . (int) $plan->id . ':' . $nonce,
        ]])->all();
        $keyboard[] = [[
            'text' => $this->text('button_cancel', $locale),
            'callback_data' => 'action:cancel',
        ]];
        $this->sendMessage($msg, $this->text('reseller_choose_plan', $locale), ['inline_keyboard' => $keyboard]);
        return true;
    }

    protected function selectResellerPlan(object $msg, int $planId, string $nonce): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $state = $this->resellerStateForActor($msg, $actor);
        $plan = $this->resellerService->availablePlans()->firstWhere('id', $planId);
        if (!$plan
            || !$this->stateMatches($state, $nonce, 'plan')
            || !in_array($state['mode'] ?? '', ['create', 'purchase'], true)) {
            $this->sendMessage($msg, $this->text('operation_expired', $this->localeForUser($actor)));
            return true;
        }
        if (($state['mode'] ?? '') === 'purchase'
            && !$this->resellerService->ownedCustomer($actor, (int) ($state['customer_id'] ?? 0))) {
            $this->clearResellerState($msg);
            $this->sendMessage($msg, $this->text('customer_not_found', $this->localeForUser($actor)));
            return true;
        }

        $periods = $this->resellerService->availablePeriods($plan);
        if ($periods === []) {
            $this->sendMessage($msg, $this->text('reseller_no_period', $this->localeForUser($actor)));
            return true;
        }
        $state = array_merge($state, ['step' => 'period', 'plan_id' => (int) $plan->id]);
        $this->setResellerState($msg, $state);
        $locale = $this->localeForUser($actor);
        $keyboard = array_map(fn (string $period) => [[
            'text' => $this->periodName($period, $locale),
            'callback_data' => 'reseller:period:' . $period . ':' . $nonce,
        ]], $periods);
        $keyboard[] = [[
            'text' => $this->text('button_cancel', $locale),
            'callback_data' => 'action:cancel',
        ]];
        $this->sendMessage($msg, $this->text('reseller_choose_period', $locale, [
            'plan' => Helper::escapeMarkdown((string) $plan->name),
        ]), ['inline_keyboard' => $keyboard]);
        return true;
    }

    protected function selectResellerPeriod(object $msg, string $period, string $nonce): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $state = $this->resellerStateForActor($msg, $actor);
        $plan = Plan::query()
            ->whereKey((int) ($state['plan_id'] ?? 0))
            ->where('show', true)
            ->where('sell', true)
            ->first();
        if (!$this->stateMatches($state, $nonce, 'period')
            || !$plan
            || !in_array($period, $this->resellerService->availablePeriods($plan), true)
            || (($state['mode'] ?? '') === 'purchase'
                && !$this->resellerService->ownedCustomer($actor, (int) ($state['customer_id'] ?? 0)))) {
            $this->sendMessage($msg, $this->text('operation_expired', $this->localeForUser($actor)));
            return true;
        }
        $state = array_merge($state, ['step' => 'coupon', 'period' => $period]);
        $this->setResellerState($msg, $state);
        $this->sendMessage($msg, $this->text('reseller_coupon', $this->localeForUser($actor)), [
            'inline_keyboard' => [[[
                'text' => $this->text('button_cancel', $this->localeForUser($actor)),
                'callback_data' => 'action:cancel',
            ]]],
        ]);
        return true;
    }

    protected function completeResellerPurchase(object $msg, User $actor, array $state, string $couponCode): void
    {
        $locale = $this->localeForUser($actor);
        $nonce = (string) ($state['nonce'] ?? '');
        if (!$this->stateMatches($state, $nonce, 'coupon')) {
            $this->sendMessage($msg, $this->text('operation_expired', $locale));
            return;
        }

        $lock = Cache::lock($this->operationLockKey('purchase', (int) $actor->id, $nonce), 120);
        if (!$lock->get()) {
            $this->sendMessage($msg, $this->text('busy', $locale));
            return;
        }
        $retryState = null;
        try {
            $currentState = $this->resellerStateForActor($msg, $actor);
            if (!$this->stateMatches($currentState, $nonce, 'coupon')
                || Cache::has($this->operationDoneKey('purchase', (int) $actor->id, $nonce))) {
                $this->sendMessage($msg, $this->text('operation_expired', $locale));
                return;
            }

            $retryState = $currentState;
            $processingState = array_merge($currentState, ['step' => 'purchase_processing']);
            $this->setResellerState($msg, $processingState);

            $planId = (int) ($currentState['plan_id'] ?? 0);
            $period = (string) ($currentState['period'] ?? '');
            if (($currentState['mode'] ?? '') === 'create') {
                $result = $this->resellerService->createCustomer(
                    $actor,
                    $planId,
                    $period,
                    $couponCode,
                    $nonce,
                    $this->canonicalUserLocale($locale),
                );
            } elseif (($currentState['mode'] ?? '') === 'purchase') {
                $result = $this->resellerService->purchaseForCustomer(
                    $actor,
                    (int) ($currentState['customer_id'] ?? 0),
                    $planId,
                    $period,
                    $couponCode,
                    $nonce,
                );
            } else {
                $this->sendMessage($msg, $this->text('operation_expired', $locale));
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram reseller purchase rejected', [
                'action' => 'purchase',
                'operator_user_id' => (int) $actor->id,
                'error_type' => $e::class,
            ]);
            if (is_array($retryState)) {
                try {
                    $this->setResellerState($msg, $retryState);
                } catch (\Throwable) {
                    // Leaving the state in purchase_processing is fail-closed:
                    // the user can restart, but this operation cannot repeat.
                }
            }
            $this->sendMessage($msg, $this->text('reseller_coupon_invalid', $locale)); return;
        } finally {
            $lock->release();
        }
        try {
            Cache::put(
                $this->operationDoneKey('purchase', (int) $actor->id, $nonce),
                true,
                self::DELIVERY_TTL_SECONDS,
            );
            $this->clearResellerState($msg);
        } catch (\Throwable $e) {
            // The persisted processing state still prevents a second order if
            // cache finalization fails after the database transaction commits.
            Log::error('Telegram reseller purchase finalization failed', [
                'action' => 'purchase_finalize',
                'operator_user_id' => (int) $actor->id,
                'error_type' => $e::class,
            ]);
        }
        $this->sendMessage($msg, $this->text('reseller_done', $locale, [
            'reference' => Helper::escapeMarkdown((string) $result['reference']),
            'plan' => Helper::escapeMarkdown((string) $result['plan']->name),
            'period' => $this->periodName((string) $result['order']->period, $locale),
            // Keep the URL raw here. TelegramService applies its one required
            // Markdown escape pass; pre-escaping would corrupt some links.
            'url' => (string) $result['subscribe_url'],
        ]), [
            'inline_keyboard' => [[[
                'text' => $this->text('button_customer', $locale),
                'callback_data' => 'reseller:customer:' . (int) $result['user']->id,
            ]], [[
                'text' => $this->text('button_reseller', $locale),
                'callback_data' => 'reseller:menu',
            ]]],
        ]);
    }

    protected function showResellerCustomers(object $msg, int $page): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $this->clearResellerState($msg);
        $locale = $this->localeForUser($actor);
        $customers = $this->resellerService->ownedCustomers($actor, $page);
        $keyboard = [];
        foreach ($customers->items() as $customer) {
            $plan = $customer->plan?->name ?: $this->text('no_plan', $locale);
            $keyboard[] = [[
                'text' => $this->resellerService->customerReference($customer) . ' · ' . $plan,
                'callback_data' => 'reseller:customer:' . (int) $customer->id,
            ]];
        }
        $navigation = [];
        if ($customers->currentPage() > 1) {
            $navigation[] = ['text' => '◀', 'callback_data' => 'reseller:customers:' . ($customers->currentPage() - 1)];
        }
        if ($customers->hasMorePages()) {
            $navigation[] = ['text' => '▶', 'callback_data' => 'reseller:customers:' . ($customers->currentPage() + 1)];
        }
        if ($navigation !== []) $keyboard[] = $navigation;
        $keyboard[] = [[
            'text' => $this->text('button_back', $locale),
            'callback_data' => 'reseller:menu',
        ]];

        $message = $customers->total() > 0
            ? $this->text('customers_title', $locale, [
                'page' => $customers->currentPage(),
                'pages' => max(1, $customers->lastPage()),
                'total' => $customers->total(),
            ])
            : $this->text('customers_empty', $locale);
        $this->sendMessage($msg, $message, ['inline_keyboard' => $keyboard]);
        return true;
    }

    protected function showResellerCustomer(object $msg, int $customerId): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $this->clearResellerState($msg);
        $locale = $this->localeForUser($actor);
        $info = $this->resellerService->customerInfo($actor, $customerId);
        if (!$info) {
            $this->sendMessage($msg, $this->text('customer_not_found', $locale));
            return true;
        }
        $expires = $info['expired_at']
            ? date('Y-m-d H:i:s', (int) $info['expired_at'])
            : $this->text('expires_never', $locale);
        $created = $info['created_at']
            ? date('Y-m-d H:i:s', (int) $info['created_at'])
            : '-';
        $this->sendMessage($msg, $this->text('customer_info', $locale, [
            'reference' => Helper::escapeMarkdown((string) $info['reference']),
            'status' => $this->text($info['active'] ? 'status_active' : 'status_inactive', $locale),
            'banned' => $this->text($info['banned'] ? 'status_banned' : 'status_not_banned', $locale),
            'plan' => $info['plan_name']
                ? Helper::escapeMarkdown((string) $info['plan_name'])
                : $this->text('no_plan', $locale),
            'expires' => $expires,
            'used' => $this->gb((int) $info['traffic_used']),
            'total' => $this->gb((int) $info['traffic_total']),
            'remaining' => $this->gb((int) $info['traffic_remaining']),
            'devices' => $info['device_limit'] ?? $this->text('unlimited', $locale),
            'created' => $created,
        ]), [
            'inline_keyboard' => [
                [['text' => $this->text('button_url', $locale), 'callback_data' => 'reseller:url:' . $customerId]],
                [['text' => $this->text('button_purchase', $locale), 'callback_data' => 'reseller:purchase:' . $customerId]],
                [['text' => $this->text('button_reset_url', $locale), 'callback_data' => 'reseller:reset-confirm:' . $customerId]],
                [['text' => $this->text('button_back', $locale), 'callback_data' => 'reseller:customers:1']],
            ],
        ]);
        return true;
    }

    protected function showCustomerSubscription(object $msg, int $customerId): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $locale = $this->localeForUser($actor);
        $url = $this->resellerService->subscriptionUrl($actor, $customerId);
        if (!$url) {
            $this->sendMessage($msg, $this->text('customer_not_found', $locale));
            return true;
        }
        $this->sendMessage($msg, $this->text('customer_url', $locale, ['url' => $url]), [
            'inline_keyboard' => [[[
                'text' => $this->text('button_back', $locale),
                'callback_data' => 'reseller:customer:' . $customerId,
            ]]],
        ]);
        return true;
    }

    protected function confirmCustomerSubscriptionReset(object $msg, int $customerId): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $locale = $this->localeForUser($actor);
        if (!$this->resellerService->ownedCustomer($actor, $customerId)) {
            $this->sendMessage($msg, $this->text('customer_not_found', $locale));
            return true;
        }
        $state = $this->beginResellerState($msg, $actor, [
            'step' => 'reset_confirmation',
            'mode' => 'reset',
            'customer_id' => $customerId,
        ]);
        $nonce = (string) $state['nonce'];
        $this->sendMessage($msg, $this->text('customer_reset_confirm', $locale), [
            'inline_keyboard' => [[
                ['text' => $this->text('button_confirm', $locale), 'callback_data' => 'reseller:reset:' . $customerId . ':' . $nonce],
                ['text' => $this->text('button_cancel', $locale), 'callback_data' => 'reseller:customer:' . $customerId],
            ]],
        ]);
        return true;
    }

    protected function resetCustomerSubscription(object $msg, int $customerId, string $nonce): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        $locale = $this->localeForUser($actor);
        $state = $this->resellerStateForActor($msg, $actor);
        if (!$this->stateMatches($state, $nonce, 'reset_confirmation')
            || (int) ($state['customer_id'] ?? 0) !== $customerId) {
            $this->sendMessage($msg, $this->text('operation_expired', $locale));
            return true;
        }

        $lock = Cache::lock($this->operationLockKey('reset', (int) $actor->id, $nonce), 30);
        if (!$lock->get()) {
            $this->sendMessage($msg, $this->text('busy', $locale));
            return true;
        }
        try {
            $currentState = $this->resellerStateForActor($msg, $actor);
            if (!$this->stateMatches($currentState, $nonce, 'reset_confirmation')
                || (int) ($currentState['customer_id'] ?? 0) !== $customerId
                || Cache::has($this->operationDoneKey('reset', (int) $actor->id, $nonce))) {
                $this->sendMessage($msg, $this->text('operation_expired', $locale));
                return true;
            }
            $processingState = array_merge($currentState, ['step' => 'reset_processing']);
            $this->setResellerState($msg, $processingState);
            $url = $this->resellerService->resetSubscription($actor, $customerId, $nonce);
            if (!$url) {
                $this->clearResellerState($msg);
                $this->sendMessage($msg, $this->text('customer_not_found', $locale));
                return true;
            }
        } finally {
            $lock->release();
        }
        try {
            Cache::put(
                $this->operationDoneKey('reset', (int) $actor->id, $nonce),
                true,
                self::DELIVERY_TTL_SECONDS,
            );
            $this->clearResellerState($msg);
        } catch (\Throwable $e) {
            Log::error('Telegram reseller reset finalization failed', [
                'action' => 'reset_finalize',
                'operator_user_id' => (int) $actor->id,
                'error_type' => $e::class,
            ]);
        }
        $this->sendMessage($msg, $this->text('customer_url_reset', $locale, ['url' => $url]), [
            'inline_keyboard' => [[[
                'text' => $this->text('button_customer', $locale),
                'callback_data' => 'reseller:customer:' . $customerId,
            ]]],
        ]);
        return true;
    }

    protected function startCustomerPurchase(object $msg, int $customerId): bool
    {
        $actor = $this->resellerActor($msg); if (!$actor) return true;
        if (!$this->resellerService->ownedCustomer($actor, $customerId)) {
            $this->sendMessage($msg, $this->text('customer_not_found', $this->localeForUser($actor)));
            return true;
        }
        $state = $this->beginResellerState($msg, $actor, [
            'step' => 'plan',
            'mode' => 'purchase',
            'customer_id' => $customerId,
        ]);
        return $this->showResellerPlans($msg, $actor, $state);
    }

    public function handleCancelCommand(object $msg): void
    {
        $this->clearResellerState($msg);
        $this->clearConfirmation($msg, 'unbind');
        $locale = $this->localeForMessage($msg);
        $this->sendMessage($msg, $this->text('cancelled', $locale), [
            'inline_keyboard' => [[[
                'text' => $this->text('button_menu', $locale),
                'callback_data' => 'action:menu',
            ]]],
        ]);
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
        $this->telegramService->sendMessage($user->telegram_id, $this->text($key, $locale, [
            'plan' => Helper::escapeMarkdown((string) ($order->plan?->name ?? '-')),
            'expires' => $expires,
        ]));
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
        if ((string) $ticket->subject === self::SUPPORT_TICKET_SUBJECT) {
            $this->sendSupportTicketNotify($ticket);
            return;
        }
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

    protected function sendSupportTicketNotify(Ticket $ticket): void
    {
        $chatId = $this->supportChatId();
        if ($chatId === null || (int) $ticket->status !== Ticket::STATUS_OPENING) return;
        $message = $ticket->messages()->latest()->first();
        $user = User::find($ticket->user_id);
        if (!$message
            || !$user
            || (int) $message->user_id !== (int) $user->id
            || !$this->resellerService->canManage($user)) {
            return;
        }
        try {
            $locale = $this->localeForUser($user);
            $reference = $this->supportReference($ticket);
            $body = $this->text('support_admin_relay', $locale, [
                'message' => Helper::escapeMarkdown((string) $message->message),
            ]) . "\n\nZG-SUPPORT-REF: `" . $reference . '`';
            $this->telegramService->sendMessage($chatId, $body, 'markdown');
        } catch (\Throwable $e) {
            // The ticket message is durable and remains visible in XBoard.
            // Never retry the non-idempotent persistence step just because the
            // external Telegram relay failed.
            Log::error('Telegram reseller support relay failed', [
                'action' => 'support_relay',
                'customer_user_id' => (int) $user->id,
                'ticket_id' => (int) $ticket->id,
                'error_type' => $e::class,
            ]);
        }
    }

    public function handleSupportAdminReply(object $msg, array $matches): void
    {
        $chatId = $this->supportChatId();
        if ($chatId === null || !hash_equals($chatId, trim((string) ($msg->chat_id ?? '')))) return;
        if ($this->deliveryKey($msg) === null) return;
        $isPrivate = (bool) ($msg->is_private ?? false);
        if ((str_starts_with($chatId, '-') && $isPrivate)
            || (!str_starts_with($chatId, '-') && !$isPrivate)) {
            return;
        }

        $admin = $this->boundUser($msg, false);
        $locale = $admin ? $this->localeForUser($admin) : $this->localeForMessage($msg);
        if (!$admin || !$admin->is_admin) {
            $this->sendMessage($msg, $this->text('forbidden', $locale));
            return;
        }
        $message = trim((string) ($msg->text ?? ''));
        if ($message === '' || mb_strlen($message) > self::SUPPORT_MESSAGE_MAX_LENGTH) {
            $this->sendMessage($msg, $this->text('support_message_invalid', $locale, [
                'limit' => self::SUPPORT_MESSAGE_MAX_LENGTH,
            ]));
            return;
        }
        $rateKey = 'telegram:support:admin:' . (int) $admin->id;
        if (RateLimiter::tooManyAttempts($rateKey, self::SUPPORT_RATE_ATTEMPTS)) {
            $this->sendMessage($msg, $this->text('support_rate_limited', $locale));
            return;
        }
        RateLimiter::hit($rateKey, self::SUPPORT_RATE_DECAY_SECONDS);

        $ticketId = $this->ticketIdFromSupportReference((string) ($matches[1] ?? ''));
        $ticket = $ticketId === null ? null : Ticket::query()
            ->whereKey($ticketId)
            ->where('subject', self::SUPPORT_TICKET_SUBJECT)
            ->where('status', Ticket::STATUS_OPENING)
            ->with('user')
            ->first();
        $target = $ticket?->user;
        $targetTelegramId = trim((string) ($target?->telegram_id ?? ''));
        if (!$ticket
            || !$target
            || !$this->resellerService->canManage($target)
            || preg_match('/^[1-9][0-9]{0,19}$/', $targetTelegramId) !== 1) {
            $this->sendMessage($msg, $this->text('support_reference_invalid', $locale));
            return;
        }

        $latestMessageId = (int) ($ticket->messages()->max('id') ?: 0);
        $saved = false;
        try {
            (new TicketService())->replyByAdmin(
                (int) $ticket->id,
                $message,
                (int) $admin->id,
                self::SUPPORT_TICKET_SUBJECT,
            );
            $saved = true;
        } catch (\Throwable $e) {
            // replyByAdmin persists first, then runs hooks and queues email.
            // Distinguish a true write failure from a post-commit side-effect
            // failure so Telegram cannot retry and duplicate the reply.
            try {
                $saved = $ticket->messages()
                    ->where('id', '>', $latestMessageId)
                    ->where('user_id', (int) $admin->id)
                    ->where('message', $message)
                    ->exists();
            } catch (\Throwable) {
                $saved = false;
            }
            Log::log($saved ? 'error' : 'warning', $saved
                ? 'Telegram reseller support admin post-save action failed'
                : 'Telegram reseller support admin reply rejected', [
                    'action' => $saved ? 'support_admin_post_save' : 'support_admin_reply',
                    'operator_user_id' => (int) $admin->id,
                    'ticket_id' => (int) $ticket->id,
                    'error_type' => $e::class,
                ]);
            if (!$saved) {
                try {
                    $this->sendMessage($msg, $this->text('support_failed', $locale));
                } catch (\Throwable) {
                }
                return;
            }
        }

        $delivered = true;
        try {
            $this->telegramService->sendMessage(
                $targetTelegramId,
                $this->text('support_admin_reply', $this->localeForUser($target), [
                    'message' => Helper::escapeMarkdown($message),
                ]),
                'markdown',
            );
        } catch (\Throwable $e) {
            $delivered = false;
            Log::error('Telegram reseller support reply delivery failed', [
                'action' => 'support_reply_delivery',
                'operator_user_id' => (int) $admin->id,
                'customer_user_id' => (int) $target->id,
                'ticket_id' => (int) $ticket->id,
                'error_type' => $e::class,
            ]);
        }
        try {
            $this->sendMessage(
                $msg,
                $this->text($delivered ? 'support_admin_delivered' : 'support_admin_saved', $locale),
            );
        } catch (\Throwable $e) {
            // The ticket reply is already durable. Swallow acknowledgement
            // failures so Telegram cannot replay and duplicate it.
            Log::error('Telegram reseller support acknowledgement failed', [
                'action' => 'support_admin_ack',
                'operator_user_id' => (int) $admin->id,
                'ticket_id' => (int) $ticket->id,
                'error_type' => $e::class,
            ]);
        }
    }

    public function handleTicketReply(object $msg, array $matches): void
    {
        $actor = $this->operatorUser($msg);
        if (!$actor || (!$actor->is_admin && !$actor->is_staff)) { $this->sendMessage($msg, $this->text('forbidden', $this->localeForMessage($msg))); return; }
        $ticketId = (int) ($matches[1] ?? 0);
        $ticket = $ticketId ? Ticket::find($ticketId) : null;
        if (!$ticket) return;
        // Dedicated reseller-support tickets never accept the legacy numeric
        // reply path. Even an admin must reply to the opaque reference in the
        // configured support chat, while staff have no support authority.
        if ((string) $ticket->subject === self::SUPPORT_TICKET_SUBJECT) {
            $this->sendMessage($msg, $this->text('support_reference_invalid', $this->localeForUser($actor)));
            return;
        }
        (new TicketService())->replyByAdmin($ticketId, $msg->text, $actor->id);
        $this->sendMessage($msg, $this->text('ticket_replied', $this->localeForMessage($msg), ['id' => $ticketId]));
    }

    public function handleUnknownCommand(array $data): void
    {
        [$msg] = $data; if ($msg->message_type === 'message') $this->sendMessage($msg, $this->text('unknown', $this->localeForMessage($msg)));
    }

    public function handleError(array $data): void
    {
        [$msg, $e] = $data;
        Log::error('Telegram message handler error', array_filter([
            'action' => $this->logAction($msg),
            'operator_user_id' => $this->boundUserIdForLog($msg),
            'error_type' => $e::class,
        ], static fn ($value) => $value !== null));
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
        $actorId = $this->actorId($msg);
        $user = $actorId === '' ? null : User::where('telegram_id', $actorId)->first();
        if (!$user && $notify) $this->sendMessage($msg, $this->text('bind_first', $this->localeForMessage($msg)));
        return $user;
    }

    protected function operatorUser(object $msg): ?User { return $this->boundUser($msg, false); }
    protected function isOperator(object $msg): bool { $u = $this->operatorUser($msg); return (bool) ($u && ($u->is_admin || $u->is_staff)); }
    protected function isAdmin(object $msg): bool { $u = $this->operatorUser($msg); return (bool) ($u && $u->is_admin); }
    protected function isReseller(object $msg): bool
    {
        if (!$this->getConfig('enable_reseller_bot', false)) return false;
        $user = $this->boundUser($msg, false);
        return (bool) ($user && $this->resellerService->canManage($user));
    }

    protected function resellerActor(object $msg, bool $notify = true): ?User
    {
        $user = $this->boundUser($msg, false);
        if (!$user) {
            if ($notify) $this->sendMessage($msg, $this->text('bind_first', $this->localeForMessage($msg)));
            return null;
        }
        if (!$this->getConfig('enable_reseller_bot', false) || !$this->resellerService->canManage($user)) {
            if ($notify) $this->sendMessage($msg, $this->text('forbidden', $this->localeForUser($user)));
            return null;
        }
        return $user;
    }

    /** Keep Telegram user identifiers as decimal strings at every boundary. */
    protected function actorId(object $msg): string
    {
        $value = trim((string) ($msg->from_id ?? $msg->chat_id ?? ''));
        return preg_match('/^[1-9][0-9]{0,19}$/', $value) === 1 ? $value : '';
    }

    protected function resellerKey(object $msg): ?string
    {
        $actorId = $this->actorId($msg);
        return $actorId === '' ? null : 'telegram:reseller:state:' . $actorId;
    }

    protected function resellerState(object $msg): ?array
    {
        $key = $this->resellerKey($msg);
        if ($key === null) return null;
        $state = Cache::get($key);
        if (!is_array($state)
            || (int) ($state['expires_at'] ?? 0) < time()
            || preg_match('/^[a-f0-9]{16}$/', (string) ($state['nonce'] ?? '')) !== 1
            || (int) ($state['actor_user_id'] ?? 0) <= 0) {
            Cache::forget($key);
            return null;
        }
        return $state;
    }

    protected function resellerStateForActor(object $msg, User $actor): ?array
    {
        $state = $this->resellerState($msg);
        return $state && (int) $state['actor_user_id'] === (int) $actor->id ? $state : null;
    }

    protected function beginResellerState(object $msg, User $actor, array $state): array
    {
        $state['actor_user_id'] = (int) $actor->id;
        $state['nonce'] = $this->newNonce();
        $this->setResellerState($msg, $state);
        return $state;
    }

    protected function setResellerState(object $msg, array &$state): void
    {
        $key = $this->resellerKey($msg);
        if ($key === null) return;
        $state['expires_at'] = time() + self::RESELLER_STATE_TTL_SECONDS;
        Cache::put($key, $state, self::RESELLER_STATE_TTL_SECONDS);
    }

    protected function clearResellerState(object $msg): void
    {
        $key = $this->resellerKey($msg);
        if ($key !== null) Cache::forget($key);
    }

    protected function stateMatches(?array $state, string $nonce, string $step): bool
    {
        return $state !== null
            && preg_match('/^[a-f0-9]{16}$/', $nonce) === 1
            && hash_equals((string) $state['nonce'], $nonce)
            && ($state['step'] ?? '') === $step;
    }

    protected function rejectExpiredCallback(object $msg): bool
    {
        $this->sendMessage($msg, $this->text('operation_expired', $this->localeForMessage($msg)));
        return true;
    }

    protected function newNonce(): string
    {
        return bin2hex(random_bytes(self::FLOW_NONCE_BYTES));
    }

    protected function confirmationKey(object $msg, string $action): ?string
    {
        $actorId = $this->actorId($msg);
        return $actorId === '' ? null : 'telegram:confirmation:' . $action . ':' . $actorId;
    }

    /** @param array<string, int|string> $confirmation */
    protected function setConfirmation(object $msg, string $action, array $confirmation): void
    {
        $key = $this->confirmationKey($msg, $action);
        if ($key === null) return;
        $confirmation['expires_at'] = time() + self::CONFIRMATION_TTL_SECONDS;
        Cache::put($key, $confirmation, self::CONFIRMATION_TTL_SECONDS);
    }

    protected function clearConfirmation(object $msg, string $action): void
    {
        $key = $this->confirmationKey($msg, $action);
        if ($key !== null) Cache::forget($key);
    }

    /** @return array<string, int|string>|null */
    protected function consumeConfirmation(object $msg, string $action, string $nonce): ?array
    {
        $key = $this->confirmationKey($msg, $action);
        if ($key === null) return null;
        $confirmation = Cache::get($key);
        if (!is_array($confirmation)
            || (int) ($confirmation['expires_at'] ?? 0) < time()
            || preg_match('/^[a-f0-9]{16}$/', $nonce) !== 1
            || !hash_equals((string) ($confirmation['nonce'] ?? ''), $nonce)) {
            return null;
        }
        Cache::forget($key);
        return $confirmation;
    }

    protected function operationLockKey(string $action, int $actorUserId, string $nonce): string
    {
        return 'telegram:operation:lock:' . hash('sha256', $action . '|' . $actorUserId . '|' . $nonce);
    }

    protected function operationDoneKey(string $action, int $actorUserId, string $nonce): string
    {
        return 'telegram:operation:done:' . hash('sha256', $action . '|' . $actorUserId . '|' . $nonce);
    }

    protected function deliveryKey(object $msg): ?string
    {
        $type = (string) ($msg->message_type ?? '');
        if ($type === 'callback_query' && isset($msg->callback_query_id)) {
            $identity = trim((string) $msg->callback_query_id);
            return $identity === '' ? null : 'telegram:delivery:' . hash('sha256', 'callback|' . $identity);
        }
        $chatId = trim((string) ($msg->chat_id ?? ''));
        $messageId = trim((string) ($msg->message_id ?? ''));
        if ($chatId === '' || preg_match('/^[1-9][0-9]*$/', $messageId) !== 1) return null;
        return 'telegram:delivery:' . hash('sha256', $type . '|' . $chatId . '|' . $messageId);
    }

    protected function logAction(object $msg): string
    {
        $type = (string) ($msg->message_type ?? 'message');
        $command = (string) ($msg->command ?? '');
        if ($type === 'callback_query') {
            return str_starts_with($command, 'reseller:') ? 'reseller_callback' : 'menu_callback';
        }
        if (array_key_exists($command, $this->commandConfigs)) {
            return 'command_' . ltrim($command, '/');
        }
        return $this->resellerState($msg) ? 'reseller_input' : 'message';
    }

    protected function boundUserIdForLog(object $msg): ?int
    {
        $actorId = $this->actorId($msg);
        if ($actorId === '') return null;
        try {
            $id = User::query()->where('telegram_id', $actorId)->value('id');
            return $id === null ? null : (int) $id;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function supportChatId(): ?string
    {
        if (!$this->getConfig('enable_reseller_bot', false)
            || !$this->getConfig('enable_reseller_support_chat', false)) {
            return null;
        }
        $chatId = trim((string) $this->getConfig('reseller_support_chat_id', ''));
        return preg_match('/^-?[1-9][0-9]{0,19}$/', $chatId) === 1 ? $chatId : null;
    }

    protected function supportTicketForActor(User $actor, ?int $ticketId = null): ?Ticket
    {
        $query = Ticket::query()
            ->where('user_id', $actor->id)
            ->where('subject', self::SUPPORT_TICKET_SUBJECT)
            ->where('status', Ticket::STATUS_OPENING);
        if ($ticketId !== null) {
            $query->whereKey($ticketId);
        } else {
            $query->orderByDesc('id');
        }
        return $query->first();
    }

    protected function supportReference(Ticket $ticket): string
    {
        // APP_KEY-backed authenticated encryption keeps the database id opaque
        // while remaining resolvable after PHP, queue or host restarts.
        return bin2hex(Crypt::encryptString('telegram-support:' . (int) $ticket->id));
    }

    protected function ticketIdFromSupportReference(string $reference): ?int
    {
        $reference = trim($reference);
        if (strlen($reference) < 80
            || strlen($reference) > 1024
            || strlen($reference) % 2 !== 0
            || !ctype_xdigit($reference)) {
            return null;
        }
        try {
            $encrypted = hex2bin($reference);
            if ($encrypted === false) return null;
            $payload = Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
        if (preg_match('/^telegram-support:([1-9][0-9]{0,18})$/', $payload, $matches) !== 1) {
            return null;
        }
        $id = (int) $matches[1];
        return $id > 0 ? $id : null;
    }

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
            Log::error('Telegram locale catalog could not be loaded', [
                'locale' => $locale,
                'error_type' => $e::class,
            ]);
            $catalog = ['messages' => [], 'commands' => [], 'periods' => []];
        }
        foreach (['messages', 'commands', 'periods'] as $section) {
            if (!isset($catalog[$section]) || !is_array($catalog[$section])) $catalog[$section] = [];
        }
        return $this->catalogs[$locale] = $catalog;
    }

    protected function nodeReportInterval(): int
    {
        $raw = $this->getConfig('node_report_interval_minutes', '15');
        $value = is_scalar($raw) || $raw === null ? trim((string) $raw) : '15';
        return in_array($value, ['5', '15', '60'], true) ? (int) $value : 15;
    }

    protected function nodeReportChatId(): ?string
    {
        $raw = $this->getConfig('node_report_chat_id', '');
        $configured = is_scalar($raw) || $raw === null ? trim((string) $raw) : '';
        // A non-empty invalid plugin value fails closed instead of silently
        // sending to a legacy destination the administrator may have forgotten.
        if ($configured !== '') {
            return preg_match('/^-?[1-9][0-9]{0,19}$/', $configured) === 1 ? $configured : null;
        }
        $legacy = trim((string) admin_setting('telegram_node_report_chat_id', ''));
        return preg_match('/^-?[1-9][0-9]{0,19}$/', $legacy) === 1 ? $legacy : null;
    }

    protected function nodeReportLocale(): string
    {
        $raw = $this->getConfig('node_report_locale', '');
        $configured = is_scalar($raw) || $raw === null ? trim((string) $raw) : '';
        $value = $configured !== ''
            ? $configured
            : (string) admin_setting('telegram_node_report_locale', 'vi');
        return $this->language($value);
    }

    /** @return list<string> */
    protected function nodeReportLines(string $locale): array
    {
        $locale = $this->language($locale);
        $lines = [$this->text('nodes_title', $locale), ''];
        $servers = Server::all()->filter(fn (Server $server) => !$server->parent_id);
        foreach ($servers as $server) {
            $availability = (int) $server->available_status;
            $serverId = (int) ($server->parent_id ?: $server->id);
            $onlineCacheKey = CacheKey::get(
                'SERVER_' . strtoupper((string) $server->type) . '_ONLINE_USER',
                $serverId,
            );
            $onlineTelemetry = Cache::get($onlineCacheKey);
            if ($availability === Server::STATUS_ONLINE
                && is_numeric($onlineTelemetry)
                && (int) $onlineTelemetry >= 0) {
                $state = '🟢';
                $status = $this->text('node_status_online', $locale);
                // `online` is populated from the node's recent per-user
                // traffic telemetry. Only expose it while LAST_PUSH_AT is
                // fresh; the accessor's fallback zero is otherwise ambiguous.
                $online = $this->text('nodes_online_count', $locale, [
                    'count' => (int) $onlineTelemetry,
                ]);
            } elseif ($availability === Server::STATUS_ONLINE
                || $availability === Server::STATUS_ONLINE_NO_PUSH) {
                $state = '🟡';
                $status = $this->text('node_status_no_data', $locale);
                $online = $this->text('nodes_unavailable', $locale);
            } else {
                $state = '🔴';
                $status = $this->text('node_status_offline', $locale);
                $online = $this->text('nodes_unavailable', $locale);
            }

            $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string) $server->name)) ?? '';
            $type = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strtoupper(trim((string) $server->type))) ?? '';
            $name = mb_substr($name, 0, 160);
            $type = mb_substr($type, 0, 64);
            $lines[] = $this->text('node_line', $locale, [
                'state' => $state,
                'status' => Helper::escapeMarkdown($status),
                'name' => Helper::escapeMarkdown($name !== '' ? $name : '-'),
                'type' => Helper::escapeMarkdown($type !== '' ? $type : '-'),
                'online' => Helper::escapeMarkdown($online),
            ]);
        }
        if ($servers->isEmpty()) $lines[] = $this->text('nodes_empty', $locale);
        return $lines;
    }

    protected function nodeReport(string $locale): string
    {
        return implode("\n", $this->nodeReportLines($locale));
    }

    /** @return list<string> */
    protected function nodeReportChunks(string $locale): array
    {
        $lines = $this->nodeReportLines($locale);
        $header = (string) array_shift($lines);
        if (($lines[0] ?? null) === '') array_shift($lines);

        $chunks = [];
        $current = $header;
        $entryLimit = max(64, self::NODE_REPORT_MESSAGE_LIMIT - mb_strlen($header) - 3);
        foreach ($lines as $line) {
            if (mb_strlen($line) > $entryLimit) {
                $line = rtrim(mb_substr($line, 0, $entryLimit - 1), '\\') . '…';
            }
            $separator = $current === $header ? "\n\n" : "\n";
            $candidate = $current . $separator . $line;
            if (mb_strlen($candidate) > self::NODE_REPORT_MESSAGE_LIMIT && $current !== $header) {
                $chunks[] = $current;
                $current = $header . "\n\n" . $line;
                continue;
            }
            $current = $candidate;
        }
        $chunks[] = $current;
        return $chunks;
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
