const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const plugin = read('plugins-core/Telegram/Plugin.php');
const service = read('app/Services/TelegramResellerService.php');
const bindingService = read('app/Services/TelegramBindingService.php');
const pluginManager = read('app/Services/Plugin/PluginManager.php');
const telegramService = read('app/Services/TelegramService.php');
const config = JSON.parse(read('plugins-core/Telegram/config.json'));
const readme = read('plugins-core/Telegram/README.md');

function between(source, start, end) {
  const startIndex = source.indexOf(start);
  assert(startIndex >= 0, `Missing source marker: ${start}`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert(endIndex > startIndex, `Missing source marker after ${start}: ${end}`);
  return source.slice(startIndex, endIndex);
}

function includesAll(source, needles, contract) {
  for (const needle of needles) {
    assert(source.includes(needle), `${contract} is missing: ${needle}`);
  }
}

assert.strictEqual(config.version, '2.3.2', 'Telegram plugin version must ship the report-group persistence fix.');
assert.strictEqual(config.auto_update_on_deploy, true,
  'Telegram 2.3 must update the installed plugin record during an image deployment');
const ciGate = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deployGate = fs.readFileSync('.docker/deploy-production.sh', 'utf8');
for (const [releaseGate, gate] of [
  ['.docker/ci-smoke.sh', ciGate],
  ['.docker/deploy-production.sh', deployGate],
]) {
  assert(gate.includes("SELECT version || ':' || is_enabled FROM v2_plugins WHERE code = 'telegram';"),
    `${releaseGate} must verify the installed Telegram plugin record`);
}
assert(ciGate.includes('if [ "$telegram_plugin_state" != \'2.3.2:1\' ]; then'),
  'Fresh CI install must enable the bundled Telegram 2.3.2 plugin.');
assert(deployGate.includes("'2.3.2:0'|'2.3.2:1')"),
  'Deployment must preserve either explicit administrator-controlled Telegram enabled state.');
assert(!deployGate.includes("'2.3.2:'*)"),
  'Deployment accepts an unvalidated Telegram plugin state suffix.');
assert(ciGate.includes('runtime=/www/plugins/Telegram/Plugin.php')
  && ciGate.includes('runtime=/www/plugins-core/Telegram/Plugin.php')
  && deployGate.includes('runtime=/www/plugins/Telegram/Plugin.php')
  && deployGate.includes('runtime=/www/plugins-core/Telegram/Plugin.php')
  && ciGate.includes('grep -Fq "PluginConfigService::class)->updateConfig"')
  && deployGate.includes('grep -Fq "PluginConfigService::class)->updateConfig"'),
  'Release gates do not verify that the upgraded Telegram runtime copy contains the report-group fix.');
assert(config.config.enable_reseller_bot, 'Reseller feature flag is missing.');
for (const legacyWhitelistKey of ['reseller_telegram_ids', 'reseller_allowed_telegram_ids']) {
  assert(!Object.prototype.hasOwnProperty.call(config.config, legacyWhitelistKey),
    `Legacy Telegram-id whitelist is still in plugin config: ${legacyWhitelistKey}`);
  assert(!plugin.includes(legacyWhitelistKey),
    `Plugin still reads the legacy Telegram-id whitelist: ${legacyWhitelistKey}`);
}
assert(/is_reseller/.test(config.config.enable_reseller_bot.description)
  && /Administrator and staff roles do not grant reseller access/.test(config.config.enable_reseller_bot.description),
  'Configuration copy does not make explicit reseller assignment independent from admin/staff roles.');
assert(readme.includes('**2.3.2**') && !readme.includes('khai báo Telegram ID được phép'),
  'README metadata or reseller authority documentation is stale.');

const canManage = between(service, 'public function canManage', 'public function availablePlans');
assert(canManage.includes('return (bool) $actor->is_reseller;'),
  'Reseller authority is not tied exclusively to an explicit is_reseller assignment.');
assert(!canManage.includes('$actor->is_admin') && !canManage.includes('$actor->is_staff'),
  'Administrative or staff roles still imply reseller authority.');
includesAll(plugin, [
  "if (!$this->getConfig('enable_reseller_bot', false)) return false;",
  '$this->resellerService->canManage($user)',
  'protected function resellerActor(object $msg, bool $notify = true): ?User',
], 'Plugin reseller authorization');
const resellerInput = between(plugin, 'protected function handleResellerInput', 'protected function openResellerSupport');
assert(resellerInput.includes('if (!$this->privateChat($msg)) return;'),
  'A coupon or support text can be submitted from a Telegram group.');

const ownedCustomers = between(service, 'public function ownedCustomers', 'public function ownedCustomer');
const ownedCustomer = between(service, 'public function ownedCustomer', '/**\n     * Create a pseudonymous');
for (const block of [ownedCustomers, ownedCustomer]) {
  includesAll(block, [
    "where('invite_user_id', $actor->id)",
    "where('is_admin', false)",
    "where('is_staff', false)",
    "where('is_reseller', false)",
  ], 'Owned-customer least-privilege query');
}
assert(ownedCustomer.includes('whereKey($customerId)'), 'Customer lookup is not constrained by target id.');
assert(ownedCustomer.includes('lockForUpdate()'), 'Mutating customer lookup cannot take a row lock.');

const periods = between(service, 'public function availablePeriods', 'public function ownedCustomers');
includesAll(periods, [
  '$period === Plan::PERIOD_RESET_TRAFFIC',
  '!Plan::isValidPeriod($period)',
  '!is_numeric($price)',
  '(float) $price <= 0',
], 'Sellable reseller periods');

const createCustomer = between(service, 'public function createCustomer', '/**\n     * Renew the current plan');
includesAll(createCustomer, [
  'DB::transaction(',
  'lockForUpdate()->first()',
  '$couponId = $this->validateFullDiscountCoupon',
  '$email = $this->generateCustomerEmail();',
  "'invite_user_id' => (int) $lockedActor->id",
  "'plan_id' => 0",
  '$this->assertZeroTotalCouponOrder($order, $couponId);',
  "'subscribe_url' => Helper::getSubscribeUrl($user->token)",
], 'Pseudonymous customer transaction');
assert(createCustomer.indexOf('$this->validateFullDiscountCoupon') < createCustomer.indexOf('$this->generateCustomerEmail'),
  'A customer row can be allocated before coupon validation.');
assert(!/function createCustomer\s*\([^)]*email/is.test(createCustomer),
  'Customer creation still accepts entered customer email.');
assert(!createCustomer.includes("'password' => $email"), 'Generated customer credentials derive from an email.');
assert(createCustomer.includes("'password' => $password") && createCustomer.includes('random_bytes(24)'),
  'Generated customer credential is not cryptographically random.');
assert(!/Log::[a-z]+\([^;]*(?:\$password|\$email)/is.test(service),
  'Generated customer credentials or pseudonymous address can reach a log call.');
const audit = between(service, 'private function audit', '\n    }\n}');
assert(audit.includes('try {') && audit.includes('catch (\\Throwable)'),
  'A post-commit audit logger failure can make Telegram repeat a reseller mutation.');

const sellablePlan = between(service, 'private function sellablePlan', 'private function validateFullDiscountCoupon');
includesAll(sellablePlan, [
  "where('show', true)",
  "where('sell', true)",
  'lockForUpdate()',
  '!in_array($period, $this->availablePeriods($plan), true)',
], 'Final plan and period validation');

const validateCoupon = between(service, 'private function validateFullDiscountCoupon', 'private function assertZeroTotalCouponOrder');
includesAll(validateCoupon, [
  'new CouponService(trim($couponCode))',
  '(int) $coupon->type !== 2',
  '(float) $coupon->value !== 100.0',
  '$couponService->setPlanId((int) $plan->id);',
  '$couponService->setPeriod($period);',
  '$couponService->check();',
  'return (int) $coupon->id;',
], 'Full-discount coupon validation');
const zeroTotal = between(service, 'private function assertZeroTotalCouponOrder', 'private function generateCustomerEmail');
includesAll(zeroTotal, [
  '(int) $order->coupon_id !== $couponId',
  '(int) $order->total_amount !== 0',
  '(float) $order->discount_amount <= 0',
], 'Zero-total coupon proof');

const purchase = between(service, 'public function purchaseForCustomer', 'public function subscriptionUrl');
assert(purchase.includes('$this->ownedCustomer($lockedActor, $customerId, true)'),
  'Renew/change can target a customer outside the locked ownership query.');
for (const marker of [
  'public function subscriptionUrl',
  'public function resetSubscription',
  'public function customerInfo',
]) {
  const start = service.indexOf(marker);
  const next = service.indexOf('\n    public function ', start + marker.length);
  const block = service.slice(start, next < 0 ? service.length : next);
  assert(block.includes('$this->ownedCustomer('), `${marker} bypasses strict ownership.`);
}

const reset = between(service, 'public function resetSubscription', '/** @return array<string');
includesAll(reset, [
  '$customer->uuid = Helper::guid(true);',
  '$customer->token = Helper::guid();',
  "HookManager::call('user.subscribe.reset.after', [$customer, $url]);",
], 'Subscription security reset');
const info = between(service, 'public function customerInfo', 'public function customerReference');
for (const field of [
  'reference', 'active', 'banned', 'plan_name', 'expired_at', 'traffic_used',
  'traffic_total', 'traffic_remaining', 'device_limit', 'created_at',
]) {
  assert(info.includes(`'${field}'`), `Full customer info is missing ${field}.`);
}
assert(info.includes('(int) $customer->created_at') && !info.includes('getTimestamp()'),
  'Integer timestamp cast is still treated as a Carbon object.');
assert(!info.includes('Helper::getSubscribeUrl') && !info.includes("'token'") && !info.includes("'email'"),
  'Customer details expose a credential before the dedicated subscription-link action.');

includesAll(plugin, [
  "'inline_keyboard' => $buttons",
  "'callback_data' => 'reseller:menu'",
  "'callback_data' => 'reseller:customers:1'",
  "'callback_data' => 'reseller:plan:' . (int) $plan->id . ':' . $nonce",
  "'callback_data' => 'reseller:period:' . $period . ':' . $nonce",
  "'callback_data' => 'reseller:reset:' . $customerId . ':' . $nonce",
  "'callback_data' => 'action:unbind:yes:' . $nonce",
  "preg_match('/^[a-f0-9]{16}$/', $nonce)",
  'hash_equals((string) $state[\'nonce\'], $nonce)',
  "['step' => 'purchase_processing']",
  "['step' => 'reset_processing']",
], 'Nonce-bound button flow');
assert(!plugin.includes("'callback_data' => 'reseller:reset:' . $customerId]"),
  'A bare reset callback can bypass confirmation.');
assert(!plugin.includes("'callback_data' => 'action:unbind:yes']"),
  'A bare unbind callback can bypass confirmation.');
includesAll(plugin, [
  "Cache::add($deliveryKey, 'processing', self::DELIVERY_TTL_SECONDS)",
  "$this->operationLockKey('purchase'",
  "$this->operationLockKey('reset'",
  "$this->operationLockKey('unbind'",
  '$this->bindingService->revoke($user);',
  '$confirmation = $this->consumeConfirmation',
], 'Duplicate-delivery and one-time operation protection');
assert(bindingService.includes('public function revoke(User|int $user): void'),
  'Unbind cannot revoke outstanding dashboard binding payloads.');

const actorId = between(plugin, 'protected function actorId', 'protected function resellerKey');
assert(actorId.includes('): string') && actorId.includes("preg_match('/^[1-9][0-9]{0,19}$/', $value)"),
  'Telegram actor identifiers are not validated decimal strings at the boundary.');
assert(!plugin.includes('->getMessage()') && !service.includes('->getMessage()'),
  'Telegram reseller handling logs raw exception text.');
assert(!plugin.includes("'chat_id' =>"), 'Plugin logs a Telegram chat identifier.');
assert(plugin.includes("'plan' => Helper::escapeMarkdown((string) $result['plan']->name)"),
  'Admin-controlled plan names are not escaped in activation output.');
assert(plugin.includes("'plan' => Helper::escapeMarkdown((string) ($order->plan?->name ?? '-'))"),
  'Admin-controlled plan names are not escaped in lifecycle notifications.');
assert(plugin.includes("'url' => (string) $result['subscribe_url']"),
  'Subscription URL is missing or is pre-escaped before TelegramService.');

const localeNames = ['en', 'fa', 'ja', 'ko', 'ru', 'vi', 'zh-CN', 'zh-TW'];
const catalogs = localeNames.map((locale) => [
  locale,
  JSON.parse(read(`plugins-core/Telegram/locales/${locale}.json`)),
]);
const referenceKeys = Object.keys(catalogs[0][1].messages).sort();
for (const [locale, catalog] of catalogs) {
  assert.deepStrictEqual(Object.keys(catalog.messages).sort(), referenceKeys,
    `${locale} message catalog differs from English.`);
  assert(!Object.keys(catalog.messages).some((key) => key.startsWith('reseller_email')),
    `${locale} retains the old entered-email reseller prompts.`);
  for (const [key, value] of Object.entries(catalog.messages)) {
    if (!key.startsWith('reseller_')) continue;
    assert(!/:email|:password/i.test(value), `${locale}.${key} exposes old customer credentials.`);
  }
  for (const key of [
    'operation_expired', 'customers_title', 'customer_info', 'customer_url',
    'customer_reset_confirm', 'button_customers', 'button_purchase', 'button_reset_url',
  ]) {
    assert(typeof catalog.messages[key] === 'string' && catalog.messages[key].trim(),
      `${locale} is missing ${key}.`);
  }
}

const callbackSamples = [
  `reseller:plan:${'9'.repeat(19)}:${'a'.repeat(16)}`,
  `reseller:period:half_yearly:${'a'.repeat(16)}`,
  `reseller:reset:${'9'.repeat(19)}:${'a'.repeat(16)}`,
  `action:unbind:yes:${'a'.repeat(16)}`,
];
for (const callback of callbackSamples) {
  assert(Buffer.byteLength(callback, 'utf8') <= 64, `Callback exceeds Telegram's 64-byte limit: ${callback}`);
}

includesAll(pluginManager, [
  'if (!empty($dbPlugin->config))',
  '$plugin->setConfig($values);',
  "'version' => $newVersion",
], 'Plugin upgrade config preservation');

const publicCommands = between(plugin, 'public function addBotCommands', 'protected function sendMessage');
assert(publicCommands.includes('public function addBotCommands(array $commands): array')
  && publicCommands.includes('public function addLocalizedBotCommands(array $localized): array')
  && (publicCommands.match(/return \[\];/g) || []).length === 2,
  'The public default or localized Telegram slash-command menu is still populated.');
for (const hiddenHandler of [
  "'/start' => ['handler' => 'handleStartCommand']",
  "'/menu' => ['handler' => 'handleStartCommand']",
  "'/reseller' => ['handler' => 'handleResellerCommand']",
  "'/setreportgroup' => ['handler' => 'handleSetReportGroupCommand']",
  "'/backupdb' => ['handler' => 'handleBackupDatabaseCommand']",
]) {
  assert(plugin.includes(hiddenHandler), `Internal compatibility/operations handler was removed: ${hiddenHandler}`);
}
const commandCleanup = between(
  telegramService,
  'public function registerBotCommands',
  'public function getMyCommands'
);
assert(commandCleanup.includes('$this->deleteMyCommands();')
  && !commandCleanup.includes("$this->request('setMyCommands'"),
  'Webhook/plugin setup does not clear legacy public command menus.');
assert(plugin.includes('public function update(string $oldVersion, string $newVersion): void')
  && plugin.includes('(new TelegramService())->registerBotCommands();'),
  'The 2.3 upgrade path does not actively clear menus left by an older deployment.');

console.log('Telegram reseller v2.3 button menu, isolated authority, ownership, coupon, nonce, idempotency, reset, logging and locale contracts verified.');
