<?php

namespace Plugin\ChinaWallet;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;

/**
 * Provider-neutral China-wallet payment method.
 *
 * Enabling this core plugin only exposes the gateway in the payment-method
 * editor. A concrete payment row remains disabled until a real provider
 * adapter has been selected and shipped.
 */
final class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const PROVIDERS = [
        'pending' => 'Not connected yet',
        'direct' => 'Direct WeChat Pay / Alipay merchant APIs',
        'stripe' => 'Stripe',
        'adyen' => 'Adyen',
        'antom' => 'Antom',
        '2c2p' => '2C2P',
    ];

    private const WALLET_MODES = [
        'both' => 'Alipay and WeChat Pay',
        'alipay' => 'Alipay only',
        'wechatpay' => 'WeChat Pay only',
    ];

    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            $methods['ChinaWallet'] = [
                'name' => 'China Wallet (Alipay / WeChat Pay)',
                'icon' => '🇨🇳',
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];

            return $methods;
        });
    }

    /** Provider credentials belong to each concrete payment record. */
    public function usesGlobalPaymentConfiguration(): bool
    {
        return false;
    }

    /** The plugin may be enabled before a merchant provider is available. */
    public function validateActivation(): void
    {
        // A payment row is validated separately before it can face customers.
    }

    public function form(): array
    {
        return [
            'china_wallet_provider' => [
                'label' => 'China-wallet provider',
                'type' => 'select',
                'required' => true,
                'default' => 'pending',
                'options' => self::PROVIDERS,
                'description' => 'Choose the provider after your merchant account has been approved.',
            ],
            'china_wallet_wallet_mode' => [
                'label' => 'Wallets shown to customers',
                'type' => 'select',
                'required' => true,
                'default' => 'both',
                'options' => self::WALLET_MODES,
                'description' => 'Show Alipay, WeChat Pay, or both on the CNY checkout page.',
            ],
            'china_wallet_merchant_label' => [
                'label' => 'Merchant label',
                'type' => 'string',
                'required' => false,
                'default' => 'ZaoGuang Service',
                'description' => 'Customer-facing merchant name shown on the checkout page.',
            ],
        ];
    }

    /** Allow incomplete drafts but reject malformed field shapes. */
    public function validatePaymentConfigurationShape(): void
    {
        $provider = $this->textConfig('china_wallet_provider', 'pending');
        $walletMode = $this->textConfig('china_wallet_wallet_mode', 'both');
        $merchantLabel = $this->getConfig('china_wallet_merchant_label', '');

        if (!array_key_exists($provider, self::PROVIDERS)) {
            throw new \InvalidArgumentException(__('The selected China-wallet provider is invalid.'));
        }
        if (!array_key_exists($walletMode, self::WALLET_MODES)) {
            throw new \InvalidArgumentException(__('The selected China-wallet option is invalid.'));
        }
        if (!is_scalar($merchantLabel) && $merchantLabel !== null) {
            throw new \InvalidArgumentException(__('China-wallet merchant label must be text.'));
        }
        if (mb_strlen(trim((string) $merchantLabel)) > 80) {
            throw new \InvalidArgumentException(__('China-wallet merchant label must not exceed 80 characters.'));
        }
    }

    /**
     * Fail closed until an authenticated create/query/webhook adapter exists.
     * Merely selecting a researched provider must never expose a fake payable
     * QR or credit an order without a provider-verified callback.
     */
    public function validatePaymentConfiguration(): void
    {
        $this->validatePaymentConfigurationShape();

        $provider = $this->textConfig('china_wallet_provider', 'pending');
        if ($provider === 'pending') {
            throw new \InvalidArgumentException(__('Choose an approved China-wallet provider before enabling this payment method.'));
        }

        throw new \InvalidArgumentException(__('The :provider China-wallet adapter is not connected yet. Keep this payment method disabled until the provider integration is installed.', [
            'provider' => self::PROVIDERS[$provider],
        ]));
    }

    public function pay($order): array
    {
        throw new ApiException(__('China Wallet is not connected to a payment provider yet.'));
    }

    public function notify($params): array|bool
    {
        throw new ApiException(__('China Wallet is not connected to a payment provider yet.'));
    }

    private function textConfig(string $key, string $default): string
    {
        $value = $this->getConfig($key, $default);
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        return trim((string) $value);
    }
}
