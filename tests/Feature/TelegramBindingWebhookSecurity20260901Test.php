<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramBindingService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            ->assertJsonMissingPath('data.bind_token');

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
            ->assertJsonPath('data.bind_url', 'https://t.me/SecureBindingBot')
            ->assertJsonPath('data.binding_expires_in', null)
            ->assertJsonMissingPath('data.telegram_id')
            ->assertJsonMissingPath('data.bind_token');
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
        $this->postJson($url, $payload)->assertOk();

        $this->assertSame(1, $calls);
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
