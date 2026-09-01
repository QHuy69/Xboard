<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\PaymentController;
use App\Models\Payment;
use App\Models\Plugin as PluginModel;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ChinaWalletPaymentMethodTest extends TestCase
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
        $this->pluginManager->install('china_wallet');
        $this->pluginManager->enable('china_wallet');
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_enabled_core_plugin_exposes_china_wallet_in_admin_gateway_list(): void
    {
        $this->assertTrue((bool) PluginModel::query()
            ->where('code', 'china_wallet')
            ->value('is_enabled'));
        $this->assertContains('ChinaWallet', PaymentService::getAllPaymentMethodNames());
    }

    public function test_admin_can_save_an_unconfigured_disabled_payment_draft(): void
    {
        $response = (new PaymentController())->save(Request::create(
            '/api/v2/admin/payment/save',
            'POST',
            [
                'name' => 'China Wallet',
                'icon' => '🇨🇳',
                'payment' => 'ChinaWallet',
                'config' => [
                    'china_wallet_provider' => 'pending',
                    'china_wallet_wallet_mode' => 'both',
                    'china_wallet_merchant_label' => 'ZaoGuang Service',
                    'unknown_secret' => 'must-not-persist',
                ],
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $payment = Payment::query()->where('payment', 'ChinaWallet')->firstOrFail();
        $this->assertFalse((bool) $payment->enable);
        $this->assertSame([
            'china_wallet_provider' => 'pending',
            'china_wallet_wallet_mode' => 'both',
            'china_wallet_merchant_label' => 'ZaoGuang Service',
        ], $payment->config);

        $form = (new PaymentService('ChinaWallet', $payment->id))->form();
        $this->assertSame('select', $form['china_wallet_provider']['type'] ?? null);
        $this->assertSame('pending', $form['china_wallet_provider']['value'] ?? null);
        $this->assertArrayHasKey('direct', $form['china_wallet_provider']['options'] ?? []);
        $this->assertSame('both', $form['china_wallet_wallet_mode']['value'] ?? null);
    }

    public function test_payment_cannot_be_exposed_before_a_real_provider_adapter_exists(): void
    {
        $payment = Payment::create([
            'uuid' => 'cnwallet',
            'name' => 'China Wallet',
            'icon' => '🇨🇳',
            'payment' => 'ChinaWallet',
            'config' => [
                'china_wallet_provider' => 'direct',
                'china_wallet_wallet_mode' => 'both',
                'china_wallet_merchant_label' => 'ZaoGuang Service',
            ],
            'enable' => false,
        ]);

        $response = (new PaymentController())->show(Request::create(
            '/api/v2/admin/payment/show',
            'POST',
            ['id' => $payment->id]
        ));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse((bool) Payment::findOrFail($payment->id)->enable);
        $this->assertStringContainsString(
            'adapter is not connected yet',
            (string) ($response->getData(true)['message'] ?? '')
        );
    }
}
