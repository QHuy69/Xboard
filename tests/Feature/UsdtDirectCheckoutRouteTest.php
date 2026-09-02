<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\UsdtDirectInvoice;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsdtDirectCheckoutRouteTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const RECEIVE_ADDRESS = 'TLsV52sRDL79HXGGm9yzwKibb6BeruhUzy';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en-US');
        $this->withoutMiddleware(InitializePlugins::class);
    }

    public function test_checkout_page_and_qr_use_only_the_opaque_capability_token(): void
    {
        [$token, $invoice, $order] = $this->makeCheckout();

        $page = $this->withHeader('Accept-Language', 'zh-Hant-TW,zh;q=0.9')
            ->get('/pay/usdt/' . $token);

        $page->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertSee('<html lang="zh-TW" dir="ltr">', false)
            ->assertSee('data-initial-status="pending"', false)
            ->assertSee('12.345678')
            ->assertSee(self::RECEIVE_ADDRESS)
            ->assertSee($order->trade_no)
            ->assertSee('/pay/usdt/' . $token . '/status', false)
            ->assertSee('/pay/usdt/' . $token . '/qr.svg', false);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $page->headers->get('Content-Security-Policy'));

        $qr = $this->get('/pay/usdt/' . $token . '/qr.svg');
        $qr->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringContainsString('<svg', $qr->getContent());
        $this->assertStringNotContainsString(
            'sandbox',
            (string) $qr->headers->get('Content-Security-Policy')
        );

        $this->get('/pay/usdt/' . str_repeat('B', 43))->assertNotFound();
        $this->get('/pay/usdt/too-short')->assertNotFound();
        $this->assertSame(hash('sha256', $token), $invoice->public_token_hash);
        $this->assertNotSame($token, $invoice->public_token_hash);
    }

    public function test_status_route_maps_invoice_and_order_lifecycle_without_exposing_ids(): void
    {
        [$token, $invoice, $order] = $this->makeCheckout();
        $statusUrl = '/pay/usdt/' . $token . '/status';
        $returnUrl = '/orders?trade_no=' . rawurlencode((string) $order->trade_no);

        $this->getJson($statusUrl)->assertOk()->assertExactJson([
            'status' => 'pending',
            'expires_at' => (int) $invoice->expires_at,
            'return_url' => $returnUrl,
        ]);

        $invoice->state = UsdtDirectInvoice::STATE_SEEN;
        $invoice->saveOrFail();
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'confirming');

        $invoice->state = UsdtDirectInvoice::STATE_MANUAL_REVIEW;
        $invoice->saveOrFail();
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'manual_review');

        $invoice->state = UsdtDirectInvoice::STATE_EXPIRED;
        $invoice->saveOrFail();
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'expired');

        $order->status = Order::STATUS_CANCELLED;
        $order->saveOrFail();
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('status', 'cancelled');

        $order->status = Order::STATUS_PROCESSING;
        $order->saveOrFail();
        $this->getJson($statusUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('status', 'paid');
    }

    public function test_expired_checkout_is_rendered_as_expired_before_javascript_polls(): void
    {
        [$token, $invoice] = $this->makeCheckout([
            'state' => UsdtDirectInvoice::STATE_EXPIRED,
            'expires_at' => time() - 1,
        ]);

        $this->get('/pay/usdt/' . $token . '?lang=ru-RU')
            ->assertOk()
            ->assertSee('data-initial-status="expired"', false)
            ->assertSee('data-state="expired"', false)
            ->assertSee('Счёт истёк. Не отправляйте средства.');

        $this->assertSame(UsdtDirectInvoice::STATE_EXPIRED, $invoice->state);
    }

    public function test_checkout_rejects_an_invoice_that_is_not_canonical_usdt_trc20(): void
    {
        [$token, $invoice] = $this->makeCheckout();

        $invoice->network = 'ethereum';
        $invoice->saveOrFail();
        $this->get('/pay/usdt/' . $token)->assertStatus(503);

        $invoice->network = 'tron';
        $invoice->token_contract = self::RECEIVE_ADDRESS;
        $invoice->saveOrFail();
        $this->get('/pay/usdt/' . $token)->assertStatus(503);

        $invoice->token_contract = self::TOKEN_CONTRACT;
        $invoice->expected_amount_raw = str_repeat('9', 40);
        $invoice->saveOrFail();
        $this->get('/pay/usdt/' . $token)->assertOk();

        $invoice->expected_amount_raw = str_repeat('9', 41);
        $invoice->saveOrFail();
        $this->get('/pay/usdt/' . $token)->assertStatus(503);
    }

    /**
     * @return array{0: string, 1: UsdtDirectInvoice, 2: Order}
     */
    private function makeCheckout(array $invoiceOverrides = []): array
    {
        $payment = Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => 'UsdtDirect',
            'name' => 'USDT Direct route test',
            'icon' => 'UsdtDirect',
            'config' => [],
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => true,
        ]);
        $user = User::query()->create([
            'email' => 'usdt-route-' . bin2hex(random_bytes(4)) . '@example.test',
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
        $plan = Plan::query()->create([
            'group_id' => null,
            'transfer_enable' => 5,
            'name' => 'USDT Direct route plan',
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
        $order = Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_id' => $payment->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'usdt_' . bin2hex(random_bytes(8)),
            'total_amount' => 1234,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
        ]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $invoice = UsdtDirectInvoice::query()->create(array_merge([
            'order_id' => $order->id,
            'checkout_id' => random_int(1000, 999999),
            'payment_id' => $payment->id,
            'payment_uuid' => $payment->uuid,
            'public_token_hash' => hash('sha256', $token),
            'network' => 'tron',
            'token_contract' => self::TOKEN_CONTRACT,
            'receiving_address' => self::RECEIVE_ADDRESS,
            'expected_amount_raw' => '12345678',
            'exchange_rate' => '7.250000',
            'required_confirmations' => 20,
            'state' => UsdtDirectInvoice::STATE_AWAITING,
            'expires_at' => time() + 1800,
            'config_snapshot' => null,
        ], $invoiceOverrides));

        return [$token, $invoice, $order];
    }
}
