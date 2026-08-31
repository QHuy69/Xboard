<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\PaymentController as AdminPaymentController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoinPaymentsAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en-US');
        config()->set('app.url', 'https://xboard.example.test');
        admin_setting(['app_url' => 'https://xboard.example.test']);

        HookManager::reset();
        $this->pluginManager = app(PluginManager::class);
        $this->pluginManager->install('coin_payments');
        $this->pluginManager->enable('coin_payments');
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_incomplete_enabled_record_cannot_reach_provider_or_create_checkout_claim(): void
    {
        $payment = $this->makePayment([
            'enable' => true,
            'config' => [],
        ]);
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        Sanctum::actingAs($user);
        Http::fake();

        $methods = $this->getJson('/api/v1/user/order/getPaymentMethod');
        $methods->assertOk();
        $this->assertNotContains($payment->id, collect($methods->json('data'))->pluck('id')->all());

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);

        $response->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => __('Payment method is not available'),
        ]);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('v2_order_payment_checkout', [
            'order_id' => $order->id,
        ]);
        $freshOrder = Order::findOrFail($order->id);
        $this->assertNull($freshOrder->payment_id);
        $this->assertNull($freshOrder->handling_amount);
    }

    public function test_disabled_payment_record_cannot_reach_provider_or_create_checkout_claim(): void
    {
        $payment = $this->makePayment([
            'enable' => false,
            'config' => $this->validConfig(),
        ]);
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        Sanctum::actingAs($user);
        Http::fake();

        $methods = $this->getJson('/api/v1/user/order/getPaymentMethod');
        $methods->assertOk();
        $this->assertNotContains($payment->id, collect($methods->json('data'))->pluck('id')->all());

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);

        $response->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => __('Payment method is not available'),
        ]);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('v2_order_payment_checkout', [
            'order_id' => $order->id,
        ]);
        $freshOrder = Order::findOrFail($order->id);
        $this->assertNull($freshOrder->payment_id);
        $this->assertNull($freshOrder->handling_amount);
    }

    public function test_disabled_plugin_hides_and_rejects_an_enabled_configured_record(): void
    {
        $payment = $this->makePayment([
            'enable' => true,
            'config' => $this->validConfig(),
        ]);
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        Sanctum::actingAs($user);
        Http::fake();

        $this->pluginManager->disable('coin_payments');

        $methods = $this->getJson('/api/v1/user/order/getPaymentMethod');
        $methods->assertOk();
        $this->assertNotContains($payment->id, collect($methods->json('data'))->pluck('id')->all());

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);

        $response->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => __('Payment method is not available'),
        ]);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('v2_order_payment_checkout', [
            'order_id' => $order->id,
        ]);
        $freshOrder = Order::findOrFail($order->id);
        $this->assertNull($freshOrder->payment_id);
        $this->assertNull($freshOrder->handling_amount);
    }

    public function test_legacy_global_credentials_do_not_rescue_an_incomplete_payment_record(): void
    {
        $installed = PluginModel::query()->where('code', 'coin_payments')->firstOrFail();
        $installed->config = json_encode([
            'display_name' => 'Legacy CoinPayments',
            ...$this->validConfig(),
        ], JSON_THROW_ON_ERROR);
        $installed->saveOrFail();

        // Rehydrate the cached plugin with the legacy DB payload. The payment
        // service must still ignore it for CoinPayments payment rows.
        HookManager::reset();
        $this->pluginManager->prepareForRequest();
        $this->pluginManager->initializeEnabledPlugins();

        $payment = $this->makePayment([
            'enable' => false,
            'config' => [],
        ]);
        $service = new PaymentService('CoinPayments', $payment->id);
        $form = $service->form();

        $this->assertSame('', $form['coinpayments_client_id']['value'] ?? null);
        $this->assertSame('', $form['coinpayments_client_secret']['value'] ?? null);
        $this->assertFalse($form['coinpayments_client_secret']['has_value'] ?? true);

        try {
            $service->validateConfiguration();
            $this->fail('Legacy plugin-global credentials made an incomplete payment record valid.');
        } catch (\InvalidArgumentException) {
            // Expected: required values must come from this payment record.
        }

        $toggle = (new AdminPaymentController())->show(
            Request::create('/api/v2/admin/payment/show', 'POST', ['id' => $payment->id])
        );
        $this->assertSame(400, $toggle->getStatusCode());
        $this->assertSame('fail', $toggle->getData(true)['status'] ?? null);
        $this->assertFalse(Payment::findOrFail($payment->id)->enable);

        // Also exercise the customer boundary against a forced legacy row.
        // Old plugin-global credentials must not make it listable or usable.
        $payment->enable = true;
        $payment->saveOrFail();
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        Sanctum::actingAs($user);
        Http::fake();

        $methods = $this->getJson('/api/v1/user/order/getPaymentMethod');
        $methods->assertOk();
        $this->assertNotContains($payment->id, collect($methods->json('data'))->pluck('id')->all());

        $checkout = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $checkout->assertStatus(400)->assertJson([
            'status' => 'fail',
            'message' => __('Payment method is not available'),
        ]);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('v2_order_payment_checkout', [
            'order_id' => $order->id,
        ]);
    }

    public function test_invalid_update_of_enabled_record_is_rejected_without_persisting_changes(): void
    {
        $payment = $this->makePayment([
            'name' => 'CoinPayments stable',
            'enable' => true,
            'config' => $this->validConfig(),
        ]);
        $originalConfig = $payment->config;

        $response = (new AdminPaymentController())->save(Request::create(
            '/api/v2/admin/payment/save',
            'POST',
            [
                'id' => $payment->id,
                'name' => 'CoinPayments invalid update',
                'icon' => 'CoinPayments',
                'payment' => 'CoinPayments',
                'config' => array_replace($this->validConfig(), [
                    'coinpayments_api_base' => 'http://insecure.example.test',
                ]),
            ]
        ));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('fail', $response->getData(true)['status'] ?? null);

        $payment->refresh();
        $this->assertTrue($payment->enable);
        $this->assertSame('CoinPayments stable', $payment->name);
        $this->assertSame($originalConfig, $payment->config);
        (new PaymentService('CoinPayments', $payment->id))->validateConfiguration();
    }

    public function test_incomplete_legacy_record_can_still_be_disabled(): void
    {
        $payment = $this->makePayment([
            'enable' => true,
            'config' => [],
        ]);

        $response = (new AdminPaymentController())->show(
            Request::create('/api/v2/admin/payment/show', 'POST', ['id' => $payment->id])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->getData(true)['status'] ?? null);
        $this->assertTrue($response->getData(true)['data'] ?? false);
        $this->assertFalse(Payment::findOrFail($payment->id)->enable);
    }

    private function validConfig(): array
    {
        return [
            'coinpayments_client_id' => 'payment-client',
            'coinpayments_client_secret' => 'payment-secret',
            'coinpayments_invoice_currency_id' => '5057',
            'coinpayments_payment_currency' => '',
            'coinpayments_cny_invoice_rate' => 0.14,
            'coinpayments_api_base' => 'https://a-api.coinpayments.net',
            'coinpayments_webhook_url' => 'https://xboard.example.test/api/v1/guest/payment/notify/CoinPayments/test',
            'coinpayments_webhook_max_age' => 300,
        ];
    }

    private function makePayment(array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => 'CoinPayments',
            'name' => 'CoinPayments test',
            'icon' => 'CoinPayments',
            'config' => $this->validConfig(),
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => false,
        ], $overrides));
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'email' => 'coinpayments-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
        ]);
    }

    private function makeOrder(User $user): Order
    {
        $plan = Plan::query()->create([
            'group_id' => null,
            'transfer_enable' => 5,
            'name' => 'CoinPayments availability plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 1],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => 2,
        ]);

        return Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'cp_availability_' . bin2hex(random_bytes(6)),
            'total_amount' => 100,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
        ]);
    }
}
