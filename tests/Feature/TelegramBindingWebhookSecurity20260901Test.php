<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramResellerSupportNotificationJob;
use App\Jobs\SendTelegramJob;
use App\Http\Middleware\InitializePlugins;
use App\Models\Plugin;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Services\EncryptedDatabaseBackupService;
use App\Services\TelegramBindingService;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramBindingWebhookSecurity20260901Test extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456789:telegram-security-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(InitializePlugins::class);
        Cache::flush();
        HookManager::reset();
        $this->configureTelegram('true', true, 'false');
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_bot_info_is_authenticated_and_exposes_only_an_opaque_one_time_deep_link(): void
    {
        $this->getJson('/api/v1/user/telegram/getBotInfo')->assertStatus(403);

        $user = $this->makeUser('telegram-link@example.test');
        Sanctum::actingAs($user);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['username' => 'SecureBindingBot'],
            ]),
        ]);

        $first = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $first->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.linked', false)
            ->assertJsonPath('data.username', 'SecureBindingBot')
            ->assertJsonPath('data.binding_expires_in', TelegramBindingService::TOKEN_TTL_SECONDS)
            ->assertJsonPath('data.capabilities.reseller', false)
            ->assertJsonMissingPath('data.bind_token')
            ->assertJsonMissingPath('data.subscription_url')
            ->assertJsonMissingPath('data.subscribe_url')
            ->assertJsonMissingPath('data.token');

        $firstPayload = $this->payloadFromDeepLink((string) $first->json('data.bind_url'));
        $this->assertMatchesRegularExpression('/^bind_[A-Za-z0-9_-]{32}$/', $firstPayload);

        // A focus/pageshow refresh or another tab must not revoke a link that
        // the customer is already opening in Telegram.
        $second = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $second->assertOk()
            ->assertJsonMissingPath('data.bind_token');
        $this->assertGreaterThan(0, (int) $second->json('data.binding_expires_in'));
        $this->assertLessThanOrEqual(
            TelegramBindingService::TOKEN_TTL_SECONDS,
            (int) $second->json('data.binding_expires_in')
        );
        $secondPayload = $this->payloadFromDeepLink((string) $second->json('data.bind_url'));
        $this->assertSame($firstPayload, $secondPayload);

        $binding = app(TelegramBindingService::class);
        $actorId = '4503599627370495';
        $bound = $binding->consume($firstPayload, $actorId);
        $this->assertNotNull($bound);
        $this->assertSame($actorId, $bound->telegram_id);
        $this->assertSame($actorId, $user->fresh()->telegram_id);
        $this->assertIsString($user->fresh()->telegram_id);
        $this->assertNull($binding->consume($secondPayload, $actorId));

        $user->refresh();
        Sanctum::actingAs($user);
        $linked = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $linked->assertOk()
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.bind_url', 'https://t.me/SecureBindingBot?start=menu')
            ->assertJsonPath('data.binding_expires_in', null)
            ->assertJsonMissingPath('data.telegram_id')
            ->assertJsonMissingPath('data.bind_token')
            ->assertJsonMissingPath('data.subscription_url')
            ->assertJsonMissingPath('data.subscribe_url')
            ->assertJsonMissingPath('data.token');
    }

    public function test_bot_command_menu_is_deleted_for_default_and_every_supported_language_scope(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $telegram = app(TelegramService::class);
        $this->assertTrue($telegram->commandMenuNeedsReconciliation());
        $this->assertTrue($telegram->registerBotCommands());
        $this->assertFalse($telegram->commandMenuNeedsReconciliation());

        $requests = Http::recorded()->map(static fn (array $entry) => $entry[0])->all();
        $this->assertCount(32, $requests);

        $scopeMatrix = [];
        foreach ($requests as $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->assertStringEndsWith('/deleteMyCommands', $path);
            $data = $request->data();
            $this->assertArrayNotHasKey('commands', $data);
            $scopeMatrix[] = [
                json_decode((string) ($data['scope'] ?? ''), true)['type'] ?? null,
                $data['language_code'] ?? null,
            ];
        }

        $this->assertSame(
            $this->expectedCommandMenuScopeMatrix(),
            $scopeMatrix,
        );

        $rotatedBot = new TelegramService('987654321:rotated-command-menu-token');
        $this->assertTrue($rotatedBot->commandMenuNeedsReconciliation());
    }

    public function test_command_menu_cleanup_failure_keeps_fingerprint_pending_and_reaches_later_scopes(): void
    {
        Http::fake(static function (HttpClientRequest $request) {
            $data = $request->data();
            $scopeType = json_decode((string) ($data['scope'] ?? ''), true)['type'] ?? null;
            if ($scopeType === 'all_private_chats' && ($data['language_code'] ?? null) === 'zh') {
                return Http::response(['ok' => false, 'description' => 'temporary scope failure']);
            }
            return Http::response(['ok' => true, 'result' => true]);
        });

        $telegram = app(TelegramService::class);
        $this->assertTrue($telegram->commandMenuNeedsReconciliation());
        $this->assertFalse($telegram->registerBotCommands());
        $this->assertTrue($telegram->commandMenuNeedsReconciliation());
        $this->assertSame(
            '',
            trim((string) admin_setting('telegram_bot_command_menu_fingerprint', '')),
        );

        $scopeMatrix = Http::recorded()->map(static function (array $entry) {
            $data = $entry[0]->data();
            return [
                json_decode((string) ($data['scope'] ?? ''), true)['type'] ?? null,
                $data['language_code'] ?? null,
            ];
        })->all();
        $this->assertSame(
            $this->expectedCommandMenuScopeMatrix(),
            $scopeMatrix,
        );
    }

    public function test_command_menu_retries_only_the_rate_limited_scope_then_persists_fingerprint(): void
    {
        $rateLimitedAttempts = 0;
        Http::fake(static function (HttpClientRequest $request) use (&$rateLimitedAttempts) {
            $data = $request->data();
            $scopeType = json_decode((string) ($data['scope'] ?? ''), true)['type'] ?? null;
            $isTarget = $scopeType === 'all_group_chats'
                && ($data['language_code'] ?? null) === 'ja';
            if ($isTarget && ++$rateLimitedAttempts === 1) {
                return Http::response([
                    'ok' => false,
                    'error_code' => 429,
                    'parameters' => ['retry_after' => 0],
                ], 429);
            }
            return Http::response(['ok' => true, 'result' => true]);
        });

        $telegram = app(TelegramService::class);
        $this->assertTrue($telegram->commandMenuNeedsReconciliation());
        $this->assertTrue($telegram->registerBotCommands());
        $this->assertFalse($telegram->commandMenuNeedsReconciliation());
        $this->assertSame(2, $rateLimitedAttempts);

        $scopeMatrix = Http::recorded()->map(static function (array $entry) {
            $data = $entry[0]->data();
            return [
                json_decode((string) ($data['scope'] ?? ''), true)['type'] ?? null,
                $data['language_code'] ?? null,
            ];
        })->all();
        $this->assertCount(33, $scopeMatrix);
        $this->assertSame($this->expectedCommandMenuScopeMatrix(), array_slice($scopeMatrix, 0, 32));
        $this->assertSame(['all_group_chats', 'ja'], $scopeMatrix[32]);
        $this->assertSame(
            $this->expectedCommandMenuFingerprint(self::BOT_TOKEN),
            admin_setting('telegram_bot_command_menu_fingerprint'),
        );
    }

    public function test_webhook_reports_pending_when_telegram_returns_ok_with_false_cleanup_result(): void
    {
        admin_setting([
            'telegram_webhook_url' => 'https://panel.example.test',
            'secure_path' => 'Huy2006',
        ]);
        $admin = $this->makeUser('telegram-menu-pending-admin@example.test', [
            'is_admin' => true,
        ]);
        Sanctum::actingAs($admin);

        Http::fake(static function (HttpClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/getMe')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['username' => 'RotationSecurityBot'],
                ]);
            }
            if (str_ends_with($path, '/deleteMyCommands')) {
                $data = $request->data();
                $scopeType = json_decode((string) ($data['scope'] ?? ''), true)['type'] ?? null;
                if ($scopeType === 'all_chat_administrators'
                    && ($data['language_code'] ?? null) === 'ru') {
                    return Http::response(['ok' => true, 'result' => false]);
                }
            }
            return Http::response(['ok' => true, 'result' => true]);
        });

        $this->postJson('/api/v2/Huy2006/config/setTelegramWebhook', [
            'telegram_bot_token' => self::BOT_TOKEN,
        ])->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.command_menu_cleared', false)
            ->assertJsonPath('data.command_menu_reconciliation_pending', true);

        $this->assertSame(
            '',
            trim((string) admin_setting('telegram_bot_command_menu_fingerprint', '')),
        );
        $deleteRequests = Http::recorded()->filter(static function (array $entry): bool {
            return str_ends_with(
                (string) parse_url($entry[0]->url(), PHP_URL_PATH),
                '/deleteMyCommands',
            );
        });
        $this->assertCount(32, $deleteRequests);
    }

    public function test_reseller_support_notification_queue_payload_contains_only_current_record_ids(): void
    {
        Queue::fake([SendTelegramResellerSupportNotificationJob::class]);
        $reseller = $this->makeUser('queued-support-reseller@example.test', [
            'is_reseller' => true,
            'telegram_id' => '4503599627370910',
        ]);
        $admins = collect([
            $this->makeUser('queued-support-admin-one@example.test', [
                'is_admin' => true,
                'telegram_id' => '4503599627370911',
            ]),
            $this->makeUser('queued-support-admin-two@example.test', [
                'is_admin' => true,
                'telegram_id' => '4503599627370912',
            ]),
        ])->keyBy(static fn (User $admin): string => (string) $admin->telegram_id);
        $this->makeUser('queued-support-staff@example.test', [
            'is_staff' => true,
            'telegram_id' => '4503599627370913',
        ]);

        $ticket = (new TicketService())->createTicket(
            (int) $reseller->id,
            '[Telegram reseller support]',
            1,
            'Please check this customer connection.',
        );
        $message = $ticket->messages()->latest()->firstOrFail();
        $plugin = app(PluginManager::class)->getPlugin('telegram');
        $this->assertNotNull($plugin);
        $plugin->boot();
        $plugin->sendTicketNotify($ticket);

        Queue::assertPushed(SendTelegramResellerSupportNotificationJob::class, 2);
        $jobs = Queue::pushed(SendTelegramResellerSupportNotificationJob::class);
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $adminId = (int) $this->jobProperty($job, 'adminUserId');
            $this->assertTrue($admins->contains(static fn (User $admin): bool => (int) $admin->id === $adminId));
            $this->assertSame((int) $ticket->id, $this->jobProperty($job, 'ticketId'));
            $this->assertSame((int) $message->id, $this->jobProperty($job, 'ticketMessageId'));
            $reflection = new \ReflectionClass($job);
            foreach (['telegramId', 'chatId', 'text', 'parseMode', 'replyMarkup'] as $forbiddenProperty) {
                $this->assertFalse($reflection->hasProperty($forbiddenProperty));
            }
        }
    }

    public function test_runtime_delivery_gate_blocks_direct_hooks_generic_jobs_and_backup_before_creation(): void
    {
        $this->configureTelegram('false', true, 'true');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        Queue::fake([
            SendTelegramJob::class,
            SendTelegramResellerSupportNotificationJob::class,
        ]);

        $backup = new class extends EncryptedDatabaseBackupService {
            public bool $called = false;

            public function create(string $password): string
            {
                $this->called = true;
                throw new \RuntimeException('Disabled Telegram runtime must not create a backup.');
            }
        };
        $this->app->instance(EncryptedDatabaseBackupService::class, $backup);

        $admin = $this->makeUser('runtime-gate-admin@example.test', [
            'is_admin' => true,
            'telegram_id' => '4503599627370901',
        ]);
        $user = $this->makeUser('runtime-gate-user@example.test', [
            'telegram_id' => '4503599627370902',
        ]);
        $ticket = (new TicketService())->createTicket(
            (int) $user->id,
            'Runtime-disabled Telegram ticket',
            1,
            'This content must not enter the outbound queue.',
        );

        $plugin = app(PluginManager::class)->getPlugin('telegram');
        $this->assertNotNull($plugin);
        $plugin->setConfig([
            'enable_ticket_notify' => true,
            'database_backup_password' => 'runtime-gate-password',
            'database_backup_max_mb' => '20',
        ]);
        $plugin->boot();
        $plugin->sendTicketNotify($ticket);
        $plugin->sendSubscriptionResetNotify([$user, 'https://panel.example.test/subscription']);
        $plugin->sendDatabaseBackup((int) $admin->telegram_id, 'vi');
        (new SendTelegramJob((string) $admin->telegram_id, 'Already queued content'))->handle();

        Queue::assertNotPushed(SendTelegramJob::class);
        Queue::assertNotPushed(SendTelegramResellerSupportNotificationJob::class);
        Http::assertNothingSent();
        $this->assertFalse($backup->called);
    }

    public function test_generic_queue_runtime_gate_rechecks_token_global_and_plugin_switches(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $job = new SendTelegramJob('4503599627370903', 'Queued before the integration changed.');

        $this->configureTelegram('false', true, 'true');
        $job->handle();
        $this->configureTelegram('true', true, 'true', '');
        $job->handle();
        $this->configureTelegram('true', false, 'true');
        $job->handle();

        Http::assertNothingSent();
    }

    public function test_runtime_gate_does_not_block_webhook_or_command_menu_control_plane(): void
    {
        $this->configureTelegram('false', true, 'true');
        Http::fake(static function (HttpClientRequest $request) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/getMe')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['username' => 'RuntimeGateControlPlaneBot'],
                ]);
            }
            return Http::response(['ok' => true, 'result' => true]);
        });

        $service = new TelegramService(self::BOT_TOKEN);
        $service->getMe();
        $service->setWebhook('https://panel.example.test/api/v1/guest/telegram/webhook');
        $this->assertTrue($service->registerBotCommands());

        Http::assertSentCount(34);
        Http::assertSent(static fn (HttpClientRequest $request): bool =>
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/getMe')
        );
        Http::assertSent(static fn (HttpClientRequest $request): bool =>
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/setWebhook')
        );
    }

    public function test_support_notification_worker_honors_role_unbind_and_plugin_disable_after_dispatch(): void
    {
        [$admin, $ticket, $message] = $this->supportNotificationFixture('worker-revalidation');
        $manager = app(PluginManager::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $admin->is_admin = false;
        $admin->saveOrFail();
        (new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $message->id,
        ))->handle($manager);

        $admin->is_admin = true;
        $admin->telegram_id = null;
        $admin->saveOrFail();
        (new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $message->id,
        ))->handle($manager);

        $admin->telegram_id = '4503599627370929';
        $admin->saveOrFail();
        Plugin::query()->where('code', 'telegram')->update(['is_enabled' => false]);
        (new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $message->id,
        ))->handle($manager);

        Http::assertNothingSent();
    }

    public function test_support_notification_worker_honors_global_disable_and_blank_token_after_dispatch(): void
    {
        [$admin, $ticket, $message] = $this->supportNotificationFixture('worker-integration-gate');
        $manager = app(PluginManager::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $disabledJob = new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $message->id,
        );
        admin_setting(['telegram_bot_enable' => 'false']);
        $disabledJob->handle($manager);

        admin_setting([
            'telegram_bot_enable' => 'true',
            'telegram_bot_token' => self::BOT_TOKEN,
        ]);
        $blankTokenJob = new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $message->id,
        );
        admin_setting(['telegram_bot_token' => '   ']);
        $blankTokenJob->handle($manager);

        Http::assertNothingSent();
    }

    public function test_support_notification_worker_uses_current_admin_chat_and_starts_callback_ttl_at_execution(): void
    {
        [$admin, $ticket, $message] = $this->supportNotificationFixture('worker-current-chat');
        $job = new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $message->id,
        );
        $admin->telegram_id = '4503599627370939';
        $admin->saveOrFail();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $executionStartedAt = time();
        $job->handle(app(PluginManager::class));

        Http::assertSentCount(1);
        Http::assertSent(function (HttpClientRequest $request) use ($admin, $ticket, $executionStartedAt): bool {
            $this->assertSame((string) $admin->fresh()->telegram_id, $request->data()['chat_id'] ?? null);
            $this->assertSame('markdown', $request->data()['parse_mode'] ?? null);
            $markup = json_decode((string) ($request->data()['reply_markup'] ?? ''), true);
            $callbackData = (string) ($markup['inline_keyboard'][0][0]['callback_data'] ?? '');
            $this->assertMatchesRegularExpression('/^support:view:[a-f0-9]{32}$/', $callbackData);
            $token = substr($callbackData, strlen('support:view:'));
            $payload = Cache::get('telegram:support:callback:' . hash('sha256', $token));
            $this->assertIsArray($payload);
            $this->assertSame((int) $admin->id, $payload['admin_user_id'] ?? null);
            $this->assertSame((string) $admin->telegram_id, $payload['telegram_id'] ?? null);
            $this->assertSame((int) $ticket->id, $payload['ticket_id'] ?? null);
            $this->assertGreaterThanOrEqual($executionStartedAt + 86400, (int) ($payload['expires_at'] ?? 0));
            $this->assertLessThanOrEqual(time() + 86400, (int) ($payload['expires_at'] ?? 0));
            return true;
        });
    }

    public function test_support_notification_worker_rejects_missing_nonlatest_and_closed_ticket_messages(): void
    {
        [$admin, $ticket, $message] = $this->supportNotificationFixture('worker-stale-message');
        $manager = app(PluginManager::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        (new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            2147483647,
        ))->handle($manager);

        $newerMessage = TicketMessage::query()->create([
            'user_id' => (int) $ticket->user_id,
            'ticket_id' => (int) $ticket->id,
            'message' => 'A newer durable reseller message.',
        ]);
        (new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $message->id,
        ))->handle($manager);

        $ticket->status = Ticket::STATUS_CLOSED;
        $ticket->saveOrFail();
        (new SendTelegramResellerSupportNotificationJob(
            (int) $admin->id,
            (int) $ticket->id,
            (int) $newerMessage->id,
        ))->handle($manager);

        Http::assertNothingSent();
    }

    public function test_webhook_token_rotation_uses_the_explicit_new_token_for_hash_and_bot_requests(): void
    {
        $storedToken = '111111111:stored-old-telegram-token';
        $submittedToken = '222222222:submitted-new-telegram-token';
        admin_setting([
            'telegram_bot_token' => $storedToken,
            'telegram_webhook_url' => 'https://panel.example.test',
            'secure_path' => 'Huy2006',
        ]);
        $admin = $this->makeUser('telegram-token-rotation-admin@example.test', [
            'is_admin' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->assertWebhookSetupUsesToken($submittedToken, $submittedToken);
    }

    public function test_blank_webhook_token_submission_falls_back_consistently_to_the_stored_token(): void
    {
        $storedToken = '333333333:stored-fallback-telegram-token';
        admin_setting([
            'telegram_bot_token' => $storedToken,
            'telegram_webhook_url' => 'https://panel.example.test',
            'secure_path' => 'Huy2006',
        ]);
        $admin = $this->makeUser('telegram-token-fallback-admin@example.test', [
            'is_admin' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->assertWebhookSetupUsesToken('   ', $storedToken);
    }

    public function test_binding_serializes_target_and_actor_ownership_across_distinct_tokens(): void
    {
        $binding = app(TelegramBindingService::class);
        $firstUser = $this->makeUser('telegram-owner-one@example.test');
        $secondUser = $this->makeUser('telegram-owner-two@example.test');
        $actorId = '4503599627370401';

        $firstToken = $binding->issue($firstUser)['payload'];
        $secondToken = $binding->issue($secondUser)['payload'];

        $this->assertSame(
            $firstUser->id,
            $binding->consume($firstToken, $actorId)?->id
        );
        $this->assertNull($binding->consume($secondToken, $actorId));
        $this->assertSame($actorId, $firstUser->fresh()->telegram_id);
        $this->assertNull($secondUser->fresh()->telegram_id);

        $thirdToken = $binding->issue($firstUser)['payload'];
        $this->assertNull($binding->consume($thirdToken, '4503599627370402'));
        $this->assertSame($actorId, $firstUser->fresh()->telegram_id);
    }

    public function test_actor_id_must_fit_positive_signed_bigint_without_consuming_token(): void
    {
        $binding = app(TelegramBindingService::class);
        $user = $this->makeUser('telegram-bigint-boundary@example.test');
        $payload = $binding->issue($user)['payload'];

        $this->assertNull($binding->consume($payload, '9223372036854775808'));
        $this->assertNull($user->fresh()->telegram_id);

        $validActor = '9223372036854775807';
        $this->assertSame($user->id, $binding->consume($payload, $validActor)?->id);
        $this->assertSame($validActor, $user->fresh()->telegram_id);
        $this->assertIsString($user->fresh()->telegram_id);
    }

    public function test_authenticated_unbind_revokes_an_outstanding_dashboard_link(): void
    {
        $user = $this->makeUser('telegram-revoke@example.test');
        Sanctum::actingAs($user);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['username' => 'SecureBindingBot'],
            ]),
        ]);

        $botInfo = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $payload = $this->payloadFromDeepLink((string) $botInfo->json('data.bind_url'));

        $this->postJson('/api/v1/user/telegram/unbind')
            ->assertOk()
            ->assertJsonPath('data', true);

        $binding = app(TelegramBindingService::class);
        $this->assertNull($binding->consume($payload, '4503599627370403'));
        $binding->revoke($user);
        $this->assertNull($user->fresh()->telegram_id);
    }

    public function test_enable_flags_are_normalized_and_reseller_capability_never_grants_plain_staff(): void
    {
        $staff = $this->makeUser('telegram-staff@example.test', ['is_staff' => true]);
        Sanctum::actingAs($staff);
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['username' => 'SecureBindingBot'],
            ]),
        ]);

        $this->configureTelegram('false', true, 'true');
        $disabled = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $disabled->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.bind_url', null)
            ->assertJsonPath('data.capabilities.reseller', false)
            ->assertJsonMissingPath('data.bind_token');
        Http::assertNothingSent();

        $this->configureTelegram('1', true, 'false');
        $stringFalse = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $stringFalse->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.capabilities.reseller', false);

        Plugin::query()->where('code', 'telegram')->update([
            'config' => json_encode(['enable_reseller_bot' => 'true']),
        ]);
        $stillPlainStaff = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $stillPlainStaff->assertOk()->assertJsonPath('data.capabilities.reseller', false);

        $staff->is_reseller = true;
        $staff->saveOrFail();
        $reseller = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $reseller->assertOk()->assertJsonPath('data.capabilities.reseller', true);

        $this->configureTelegram(0, true, true);
        $numericDisabled = $this->getJson('/api/v1/user/telegram/getBotInfo');
        $numericDisabled->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.bind_url', null);
    }

    public function test_webhook_uses_constant_time_auth_and_atomically_deduplicates_updates(): void
    {
        $calls = 0;
        $captured = null;
        HookManager::registerFilter(
            'telegram.message.handle',
            static function (bool $handled, array $data) use (&$calls, &$captured): bool {
                $calls++;
                $captured = $data[0];
                return true;
            }
        );

        $payload = [
            'update_id' => 712345,
            'message' => [
                'message_id' => 91,
                'from' => ['id' => 4503599627370001, 'language_code' => 'en'],
                'chat' => ['id' => 4503599627370001, 'type' => 'private'],
                'text' => '/security_probe',
            ],
        ];
        $url = '/api/v1/guest/telegram/webhook?access_token=' . md5(self::BOT_TOKEN);

        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('telegram_webhook_update_receipts', 1);

        // Dedupe authority must survive cache loss, eviction and a cache
        // backend restart. The second delivery must still be acknowledged
        // without invoking any Telegram hook again.
        Cache::flush();
        $this->postJson($url, $payload)->assertOk();

        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('telegram_webhook_update_receipts', 1);
        $receiptHash = (string) DB::table('telegram_webhook_update_receipts')
            ->value('receipt_hash');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receiptHash);
        $this->assertNotNull($captured);
        $this->assertSame('4503599627370001', $captured->from_id);
        $this->assertSame('4503599627370001', $captured->chat_id);
        $this->assertIsString($captured->from_id);
        $this->assertIsString($captured->chat_id);

        $invalid = $payload;
        $invalid['update_id'] = 712346;
        $this->postJson(
            '/api/v1/guest/telegram/webhook?access_token=invalid',
            $invalid
        )->assertStatus(401);
        $this->assertSame(1, $calls);
    }

    public function test_webhook_receipt_claim_prunes_expired_rows_but_keeps_live_receipts(): void
    {
        DB::table('telegram_webhook_update_receipts')->insert([
            [
                'receipt_hash' => str_repeat('a', 64),
                'created_at' => now()->subHours(40),
                'expires_at' => now()->subMinute(),
            ],
            [
                'receipt_hash' => str_repeat('b', 64),
                'created_at' => now(),
                'expires_at' => now()->addHour(),
            ],
        ]);

        $url = '/api/v1/guest/telegram/webhook?access_token=' . md5(self::BOT_TOKEN);
        $this->postJson($url, ['update_id' => 712347])->assertOk();

        $this->assertDatabaseMissing('telegram_webhook_update_receipts', [
            'receipt_hash' => str_repeat('a', 64),
        ]);
        $this->assertDatabaseHas('telegram_webhook_update_receipts', [
            'receipt_hash' => str_repeat('b', 64),
        ]);
        $this->assertDatabaseCount('telegram_webhook_update_receipts', 2);
    }

    public function test_disabled_webhook_acknowledges_without_auth_or_side_effects(): void
    {
        $calls = 0;
        HookManager::registerFilter(
            'telegram.message.handle',
            static function (bool $handled, array $data) use (&$calls): bool {
                $calls++;
                return true;
            }
        );

        $payload = [
            'update_id' => 812345,
            'message' => [
                'message_id' => 92,
                'from' => ['id' => 4503599627370002],
                'chat' => ['id' => 4503599627370002, 'type' => 'private'],
                'text' => '/ignored',
            ],
        ];

        $this->configureTelegram('false', true, 'true');
        $this->postJson('/api/v1/guest/telegram/webhook?access_token=invalid', $payload)
            ->assertOk();

        $this->configureTelegram('true', false, 'true');
        $payload['update_id']++;
        $this->postJson('/api/v1/guest/telegram/webhook?access_token=invalid', $payload)
            ->assertOk();

        $this->configureTelegram('true', true, 'true', '');
        $payload['update_id']++;
        $this->postJson('/api/v1/guest/telegram/webhook?access_token=invalid', $payload)
            ->assertOk();

        $this->assertSame(0, $calls);
    }

    public function test_chat_join_requests_keep_string_ids_and_share_webhook_dedupe(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $payload = [
            'update_id' => 912345,
            'chat_join_request' => [
                'chat' => ['id' => -1001234567890123],
                'from' => ['id' => 4503599627370003],
            ],
        ];
        $url = '/api/v1/guest/telegram/webhook?access_token=' . md5(self::BOT_TOKEN);

        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpClientRequest $request): bool {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->assertStringEndsWith('/declineChatJoinRequest', $path);
            $this->assertIsString($request['chat_id']);
            $this->assertIsString($request['user_id']);
            $this->assertSame('-1001234567890123', $request['chat_id']);
            $this->assertSame('4503599627370003', $request['user_id']);
            return true;
        });
    }

    private function configureTelegram(
        bool|int|string $globalEnabled,
        bool $pluginEnabled,
        bool|string $resellerEnabled,
        string $token = self::BOT_TOKEN
    ): void {
        admin_setting([
            'telegram_bot_enable' => $globalEnabled,
            'telegram_bot_token' => $token,
        ]);

        Plugin::query()->updateOrCreate(
            ['code' => 'telegram'],
            [
                'name' => 'Telegram Bot Integration',
                'version' => '2.1.0',
                'type' => Plugin::TYPE_FEATURE,
                'is_enabled' => $pluginEnabled,
                'config' => json_encode(['enable_reseller_bot' => $resellerEnabled]),
                'installed_at' => now(),
            ]
        );
    }

    private function assertWebhookSetupUsesToken(string $submittedToken, string $selectedToken): void
    {
        Http::fake(static function (HttpClientRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/getMe')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['username' => 'RotationSecurityBot'],
                ]);
            }
            return Http::response(['ok' => true, 'result' => true]);
        });

        $expectedWebhook = 'https://panel.example.test/api/v1/guest/telegram/webhook'
            . '?access_token=' . md5($selectedToken);
        $response = $this->postJson('/api/v2/Huy2006/config/setTelegramWebhook', [
            'telegram_bot_token' => $submittedToken,
        ]);
        $response->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.webhook_url', $expectedWebhook)
            ->assertJsonPath('data.command_menu_cleared', true)
            ->assertJsonPath('data.command_menu_reconciliation_pending', false);

        $requests = Http::recorded()->map(static fn (array $entry) => $entry[0]);
        $this->assertGreaterThanOrEqual(2, $requests->count());
        foreach ($requests as $request) {
            $this->assertStringStartsWith(
                'https://api.telegram.org/bot' . $selectedToken . '/',
                $request->url(),
            );
        }

        $getMe = $requests->first(static fn (HttpClientRequest $request): bool =>
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/getMe'));
        $setWebhook = $requests->first(static fn (HttpClientRequest $request): bool =>
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/setWebhook'));
        $this->assertNotNull($getMe);
        $this->assertNotNull($setWebhook);

        $webhookUrl = (string) ($setWebhook->data()['url'] ?? '');
        parse_str((string) parse_url($webhookUrl, PHP_URL_QUERY), $query);
        $this->assertSame(md5($selectedToken), $query['access_token'] ?? null);
        $this->assertSame($expectedWebhook, $webhookUrl);
    }

    /** @return array{0: User, 1: Ticket, 2: TicketMessage} */
    private function supportNotificationFixture(string $slug): array
    {
        $reseller = $this->makeUser($slug . '-reseller@example.test', [
            'is_reseller' => true,
            'telegram_id' => '4503599627370940',
        ]);
        $admin = $this->makeUser($slug . '-admin@example.test', [
            'is_admin' => true,
            'telegram_id' => '4503599627370941',
        ]);
        $ticket = (new TicketService())->createTicket(
            (int) $reseller->id,
            '[Telegram reseller support]',
            1,
            'A durable reseller support notification.',
        );
        $message = $ticket->messages()->latest()->firstOrFail();
        return [$admin, $ticket, $message];
    }

    private function expectedCommandMenuFingerprint(string $token): string
    {
        return hash('sha256', implode('|', [
            'inline-buttons-v2.3',
            implode(',', [
                'default',
                'all_private_chats',
                'all_group_chats',
                'all_chat_administrators',
            ]),
            'default-language,' . implode(',', ['vi', 'en', 'zh', 'ja', 'ko', 'fa', 'ru']),
            $token,
        ]));
    }

    private function jobProperty(object $job, string $name): mixed
    {
        $property = new \ReflectionProperty($job, $name);
        $property->setAccessible(true);
        return $property->getValue($job);
    }

    /** @return array<int, array{0: string, 1: ?string}> */
    private function expectedCommandMenuScopeMatrix(): array
    {
        $matrix = [];
        foreach ([
            'default',
            'all_private_chats',
            'all_group_chats',
            'all_chat_administrators',
        ] as $scopeType) {
            foreach ([null, 'vi', 'en', 'zh', 'ja', 'ko', 'fa', 'ru'] as $languageCode) {
                $matrix[] = [$scopeType, $languageCode];
            }
        }
        return $matrix;
    }

    private function makeUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('telegram-security-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => false,
            'is_admin' => false,
            'is_staff' => false,
            'is_reseller' => false,
            'expired_at' => 0,
            'remind_expire' => true,
            'remind_traffic' => true,
        ], $overrides));
    }

    private function payloadFromDeepLink(string $url): string
    {
        $this->assertStringStartsWith('https://t.me/SecureBindingBot?start=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('start', $query);
        return (string) $query['start'];
    }
}
