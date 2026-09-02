<?php

namespace Plugin\UsdtDirect;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\UsdtDirect\Services\TronGridClient;
use Plugin\UsdtDirect\Services\UsdtDirectConfig;

require_once __DIR__ . '/Services/TronAddress.php';
require_once __DIR__ . '/Services/UsdtAmount.php';
require_once __DIR__ . '/Services/TronGridClient.php';
require_once __DIR__ . '/Services/Trc20TransferParser.php';
require_once __DIR__ . '/Services/UsdtDirectConfig.php';
require_once __DIR__ . '/Services/UsdtDirectScanner.php';
require_once __DIR__ . '/Commands/ScanUsdtDirectTransfers.php';

/** Self-custody USDT TRC20 payment method backed by a solidified-chain scanner. */
final class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            $methods['UsdtDirect'] = [
                'name' => $this->getConfig('display_name', 'USDT (TRC20)'),
                'icon' => '₮',
                'plugin_code' => $this->getPluginCode(),
                'type' => 'plugin',
            ];

            return $methods;
        });
    }

    /** Credentials and receiving wallets are isolated per concrete payment row. */
    public function usesGlobalPaymentConfiguration(): bool
    {
        return false;
    }

    /** Plugin activation only exposes the gateway in the payment editor. */
    public function validateActivation(): void
    {
        // Per-payment validation happens before a payment row is enabled.
    }

    public function form(): array
    {
        return [
            'usdt_network' => [
                'label' => 'Network',
                'type' => 'select',
                'required' => true,
                'default' => UsdtDirectConfig::NETWORK,
                'options' => [UsdtDirectConfig::NETWORK => 'TRON Mainnet (TRC20)'],
                'description' => 'USDT Direct is pinned to TRON Mainnet.',
            ],
            'usdt_token_contract' => [
                'label' => 'USDT token contract',
                'type' => 'string',
                'required' => true,
                'default' => TronGridClient::USDT_CONTRACT,
                'description' => 'Official USDT TRC20 contract; other tokens are rejected.',
            ],
            'usdt_receive_address' => [
                'label' => 'TRON receiving address',
                'type' => 'string',
                'required' => true,
                'description' => 'Mainnet address that receives customer USDT transfers.',
            ],
            'usdt_cny_usdt_rate' => [
                'label' => 'USDT per CNY exchange rate',
                'type' => 'string',
                'required' => true,
                'description' => 'Manual rate used to convert XBoard CNY order totals to exact six-decimal USDT amounts.',
            ],
            'usdt_invoice_ttl_minutes' => [
                'label' => 'Invoice validity (minutes)',
                // Payment form values are string-schema fields in the admin
                // bundle; backend validation still enforces integer ranges.
                'type' => 'string',
                'required' => true,
                'default' => (string) UsdtDirectConfig::DEFAULT_TTL_MINUTES,
                'description' => 'Allowed range: 5 to 120 minutes.',
            ],
            'usdt_required_confirmations' => [
                'label' => 'Required confirmations',
                'type' => 'string',
                'required' => true,
                'default' => (string) UsdtDirectConfig::DEFAULT_REQUIRED_CONFIRMATIONS,
                'description' => 'Allowed range: 1 to 100. The scanner also requires a Solidity-node receipt.',
            ],
            'usdt_trongrid_api_key' => [
                'label' => 'TronGrid API key',
                'type' => 'password',
                'required' => true,
                'description' => 'Used server-side for transfer discovery and Solidity receipt verification.',
            ],
            'usdt_scan_overlap_seconds' => [
                'label' => 'Scanner overlap (seconds)',
                'type' => 'string',
                'required' => true,
                'default' => (string) UsdtDirectConfig::DEFAULT_SCAN_OVERLAP_SECONDS,
                'description' => 'Rechecks this trailing window so delayed TronGrid indexing cannot skip a transfer.',
            ],
            'usdt_scan_max_pages' => [
                'label' => 'Maximum pages per scan',
                'type' => 'string',
                'required' => true,
                'default' => (string) UsdtDirectConfig::DEFAULT_SCAN_MAX_PAGES,
                'description' => 'Safety bound for one scan. The cursor is not advanced if the bound is exceeded.',
            ],
        ];
    }

    /** Allow incomplete drafts while rejecting malformed or unsafe values. */
    public function validatePaymentConfigurationShape(): void
    {
        UsdtDirectConfig::validate($this->getConfig(), false);
    }

    public function validatePaymentConfiguration(): void
    {
        UsdtDirectConfig::validate($this->getConfig(), true);
    }

    /** Checkout creation is handled atomically by OrderService. */
    public function pay($order): array
    {
        throw new ApiException(__('USDT Direct checkout must be created through the order checkout endpoint.'));
    }

    /** USDT Direct uses polling and has no unauthenticated provider callback. */
    public function notify($params): never
    {
        throw new ApiException(__('USDT Direct does not accept payment webhooks.'), 404);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('usdt-direct:scan')
            ->name('usdt-direct-transfer-scan')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(5);
    }
}
