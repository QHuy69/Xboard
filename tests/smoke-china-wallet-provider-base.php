<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Payments\ChinaWallet\ChinaWallet;
use App\Payments\ChinaWallet\ChinaWalletCheckoutSession;
use App\Payments\ChinaWallet\ChinaWalletPaymentRequest;
use App\Payments\ChinaWallet\ChinaWalletPaymentStatus;
use App\Payments\ChinaWallet\ChinaWalletWebhookResult;

function expectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    fwrite(STDERR, $message . "\n");
    exit(1);
}

$request = new ChinaWalletPaymentRequest(
    ChinaWallet::WECHAT_PAY,
    'ORDER-CNY-001',
    8800,
    'XBoard subscription',
    'https://payments.example.test/api/notify',
    'https://payments.example.test/orders/ORDER-CNY-001',
);
if ($request->currency !== 'CNY' || $request->amountMinor !== 8800) {
    fwrite(STDERR, "China-wallet request changed its canonical amount or currency.\n");
    exit(1);
}

$session = new ChinaWalletCheckoutSession(
    'direct',
    'wx-provider-reference',
    ChinaWallet::WECHAT_PAY,
    ChinaWalletPaymentStatus::PENDING,
    ChinaWalletCheckoutSession::ACTION_QR,
    'weixin://wxpay/bizpayurl/up?pr=test',
    null,
    time() + 300,
);
if ($session->qrPayload === null || $session->status->isTerminal()) {
    fwrite(STDERR, "China-wallet QR session is not pending with a QR payload.\n");
    exit(1);
}

$webhook = new ChinaWalletWebhookResult(
    'event-001',
    'wx-provider-reference',
    'ORDER-CNY-001',
    ChinaWalletPaymentStatus::PAID,
    8800,
);
if (!$webhook->status->isTerminal()) {
    fwrite(STDERR, "Paid China-wallet webhook was not terminal.\n");
    exit(1);
}

expectInvalid(
    static fn() => new ChinaWalletPaymentRequest(
        ChinaWallet::ALIPAY,
        'ORDER-CNY-002',
        0,
        'XBoard subscription',
        'https://payments.example.test/api/notify',
        'https://payments.example.test/orders/ORDER-CNY-002',
    ),
    'China-wallet request accepted a zero amount.',
);
expectInvalid(
    static fn() => new ChinaWalletPaymentRequest(
        ChinaWallet::ALIPAY,
        'ORDER-CNY-003',
        8800,
        'XBoard subscription',
        'http://payments.example.test/api/notify',
        'https://payments.example.test/orders/ORDER-CNY-003',
    ),
    'China-wallet request accepted an insecure notification URL.',
);
expectInvalid(
    static fn() => new ChinaWalletCheckoutSession(
        'stripe',
        'pi_test',
        ChinaWallet::ALIPAY,
        ChinaWalletPaymentStatus::PENDING,
        ChinaWalletCheckoutSession::ACTION_REDIRECT,
        null,
        'javascript:alert(1)',
        time() + 300,
    ),
    'China-wallet session accepted an unsafe redirect URL.',
);

$catalog = require dirname(__DIR__) . '/config/china-wallet-providers.php';
if (($catalog['currency'] ?? null) !== 'CNY'
    || array_keys($catalog['drivers'] ?? []) !== ['direct', 'stripe', 'adyen', 'antom', '2c2p']) {
    fwrite(STDERR, "China-wallet provider catalog is incomplete or reordered unexpectedly.\n");
    exit(1);
}

echo "China-wallet PHP provider base smoke test passed.\n";
