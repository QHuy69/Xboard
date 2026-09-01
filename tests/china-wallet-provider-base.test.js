const assert = require('assert');
const fs = require('fs');

const files = {
  contract: fs.readFileSync('app/Contracts/ChinaWalletGateway.php', 'utf8'),
  wallet: fs.readFileSync('app/Payments/ChinaWallet/ChinaWallet.php', 'utf8'),
  status: fs.readFileSync('app/Payments/ChinaWallet/ChinaWalletPaymentStatus.php', 'utf8'),
  request: fs.readFileSync('app/Payments/ChinaWallet/ChinaWalletPaymentRequest.php', 'utf8'),
  session: fs.readFileSync('app/Payments/ChinaWallet/ChinaWalletCheckoutSession.php', 'utf8'),
  webhook: fs.readFileSync('app/Payments/ChinaWallet/ChinaWalletWebhookResult.php', 'utf8'),
  catalog: fs.readFileSync('config/china-wallet-providers.php', 'utf8'),
  docs: fs.readFileSync('docs/china-wallet-qr-checkout.md', 'utf8'),
  plugin: fs.readFileSync('plugins-core/ChinaWallet/Plugin.php', 'utf8'),
  pluginConfig: fs.readFileSync('plugins-core/ChinaWallet/config.json', 'utf8'),
  pluginModel: fs.readFileSync('app/Models/Plugin.php', 'utf8'),
};

for (const method of ['create(', 'query(', 'verifyWebhook(', 'refund(']) {
  assert(files.contract.includes(method), `China-wallet gateway contract is missing ${method}`);
}
assert(files.contract.includes('array $headers, string $rawBody'), 'Webhook verification must receive headers and the untouched body.');

for (const wallet of ["case WECHAT_PAY = 'wechatpay'", "case ALIPAY = 'alipay'"]) {
  assert(files.wallet.includes(wallet), `Wallet enum is missing ${wallet}`);
}
for (const status of ['PENDING', 'PAID', 'CANCELLED', 'EXPIRED', 'REFUNDING', 'REFUNDED', 'FAILED']) {
  assert(files.status.includes(`case ${status}`), `Normalized payment status is missing ${status}`);
}

for (const safety of [
  'amountMinor <= 0',
  "currency !== 'CNY'",
  'FILTER_VALIDATE_URL',
  "strtolower((string) ($parts['scheme'] ?? '')) !== 'https'",
  "isset($parts['user'])",
  "isset($parts['pass'])",
]) {
  assert(files.request.includes(safety), `Payment request validation is missing: ${safety}`);
}

assert(files.session.includes("ACTION_QR = 'qr'"), 'Normalized session is missing the QR action.');
assert(files.session.includes("ACTION_REDIRECT = 'redirect'"), 'Normalized session is missing the redirect action.');
assert(files.session.includes('QR action requires a payload'), 'QR sessions can be created without a provider payload.');
assert(files.session.includes('redirect action requires an HTTPS URL'), 'Redirect sessions can use an unsafe URL.');
assert(files.webhook.includes('eventId') && files.webhook.includes('providerReference') && files.webhook.includes('tradeNo'),
  'Verified webhook result is missing idempotency and order-correlation identifiers.');

for (const driver of ["'direct'", "'stripe'", "'adyen'", "'antom'", "'2c2p'"]) {
  assert(files.catalog.includes(driver), `Provider catalog is missing ${driver}`);
}
for (const providerField of [
  '/v3/pay/transactions/native',
  'alipay.trade.precreate',
  'next_action.wechat_pay_display_qr_code',
  'next_action.alipay_handle_redirect',
  'wechatpayQR',
  "'ALIPAY_CN'",
  "'WECHATPAY'",
  "'wechat_api_v3_key'",
  "'webhook_secret'",
  "'hmac_key'",
  "'antom_public_key'",
  "'api_hosts'",
  "'WCQR'",
  "'ALQR'",
]) {
  assert(files.catalog.includes(providerField), `Provider catalog is missing ${providerField}`);
}

assert(files.docs.includes('VPN/proxy business category'), 'Go-live checklist must require written business-category approval.');
assert(files.docs.includes('untouched webhook body'), 'Provider guide must preserve raw-body signature verification.');

const pluginConfig = JSON.parse(files.pluginConfig);
assert.strictEqual(pluginConfig.code, 'china_wallet', 'China Wallet must use a stable plugin code.');
assert.strictEqual(pluginConfig.type, 'payment', 'China Wallet must install as a payment plugin.');
assert.strictEqual(pluginConfig.auto_enable, true, 'China Wallet gateway should be visible after a deploy.');
assert.strictEqual(pluginConfig.author, 'ZaoGuang Service', 'China Wallet plugin author is incorrect.');
for (const marker of [
  "methods['ChinaWallet']",
  "'plugin_code' => $this->getPluginCode()",
  "'china_wallet_provider'",
  "'china_wallet_wallet_mode'",
  'validatePaymentConfigurationShape',
  'validatePaymentConfiguration',
  'adapter is not connected yet',
]) {
  assert(files.plugin.includes(marker), `China Wallet payment plugin is missing ${marker}`);
}
assert(files.plugin.includes('usesGlobalPaymentConfiguration(): bool'), 'China Wallet credentials must stay isolated per payment record.');
assert(files.pluginModel.includes("'china_wallet'"), 'China Wallet core plugin must be protected from deletion.');

console.log('China-wallet provider base and protected payment-method plugin are present and fail closed until a provider adapter is connected.');
