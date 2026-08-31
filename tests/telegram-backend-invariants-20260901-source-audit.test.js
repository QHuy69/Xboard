const assert = require('assert');
const fs = require('fs');

const read = (file) => fs.readFileSync(file, 'utf8');
const migrationName = '2026_09_01_000001_add_unique_telegram_id_to_users';
const migration = read(`database/migrations/${migrationName}.php`);
const receiptMigration = read('database/migrations/2026_09_01_000002_create_telegram_webhook_update_receipts.php');
const orderService = read('app/Services/OrderService.php');
const resellerService = read('app/Services/TelegramResellerService.php');
const ticketService = read('app/Services/TicketService.php');
const telegramPlugin = read('plugins-core/Telegram/Plugin.php');
const featureTest = read('tests/Feature/TelegramBackendInvariant20260901Test.php');

for (const marker of [
  "->whereNotNull('telegram_id')",
  "->groupBy('telegram_id')",
  "->havingRaw('COUNT(*) > 1')",
  'duplicate Telegram account binding exists',
  "$table->unique('telegram_id', self::UNIQUE_INDEX);",
]) {
  assert(migration.includes(marker), `Telegram unique migration is missing: ${marker}`);
}
assert(
  migration.indexOf("->havingRaw('COUNT(*) > 1')")
    < migration.indexOf("$table->unique('telegram_id', self::UNIQUE_INDEX);"),
  'Telegram unique index is created before legacy duplicates fail closed.'
);
assert(!migration.includes('->update(') && !migration.includes('->delete('),
  'Telegram unique migration silently rewrites or drops conflicting ownership data.');

for (const gate of ['.docker/ci-smoke.sh', '.docker/deploy-production.sh']) {
  assert(read(gate).includes(migrationName),
    `${gate} does not verify the Telegram uniqueness migration.`);
}

const vipStart = orderService.indexOf('public function setVipDiscount');
const vipEnd = orderService.indexOf('public function setInvite', vipStart);
const vip = orderService.slice(vipStart, vipEnd);
for (const marker of [
  '$couponDiscount = max(0, min($baseAmount,',
  '$vipDiscount = intdiv($baseAmount * $vipPercent, 100);',
  '$combinedDiscount = min($baseAmount, $couponDiscount + $vipDiscount);',
  '$order->discount_amount = $combinedDiscount;',
  '$order->total_amount = max(0, $baseAmount - $combinedDiscount);',
]) {
  assert(vip.includes(marker), `Coupon/VIP composition is missing: ${marker}`);
}
assert(!vip.includes('$order->total_amount - $order->discount_amount'),
  'VIP discount still subtracts an accumulated coupon discount twice.');

assert(
  orderService.includes('bool $allowSurplus = true') &&
    orderService.includes("if ($allowSurplus && (int) admin_setting('surplus_enable', 0))") &&
    (resellerService.match(/allowSurplus: false/g) || []).length === 2 &&
    resellerService.includes('(int) ($order->surplus_credit ?? 0) !== 0') &&
    resellerService.includes("$this->claimOperation('create'") &&
    resellerService.includes("$this->claimOperation('purchase'") &&
    resellerService.includes("$this->claimOperation('reset'") &&
    resellerService.includes('insertOrIgnore(['),
  'A 100% Telegram reseller purchase can still convert old-plan surplus into balance credit.'
);

assert(
  resellerService.includes('string $operationNonce,') &&
    resellerService.includes("$this->claimOperation('create', (int) $lockedActor->id, $operationNonce);") &&
    resellerService.includes("$this->claimOperation('purchase', (int) $lockedActor->id, $operationNonce);") &&
    resellerService.includes("$this->claimOperation('reset', (int) $lockedActor->id, $operationNonce);") &&
    resellerService.includes('"telegram-reseller\\0{$action}\\0{$actorId}\\0{$nonce}"') &&
    resellerService.includes('->insertOrIgnore([') &&
    receiptMigration.includes("$table->unique('receipt_hash', self::UNIQUE_INDEX)") &&
    (telegramPlugin.match(/\$couponCode,\s*\$nonce,/g) || []).length === 2 &&
    telegramPlugin.includes('resetSubscription($actor, $customerId, $nonce)') &&
    featureTest.includes("$operationNonce = 'a1b2c3d4e5f60718';") &&
    (featureTest.match(/\$operationNonce,/g) || []).length >= 2 &&
    featureTest.includes('A rejected purchase unexpectedly committed its operation receipt.') &&
    featureTest.includes('A durable Telegram operation receipt allowed a replayed purchase.') &&
    featureTest.includes('A durable Telegram receipt allowed a replayed customer creation.') &&
    featureTest.includes('A durable Telegram receipt allowed a replayed subscription reset.') &&
    (featureTest.match(/Cache::flush\(\);/g) || []).length >= 2,
  'Telegram create, purchase or reset can lose its durable operation nonce between plugin, service, database and regression test.'
);

assert(
  ticketService.includes("->where('status', Ticket::STATUS_OPENING)") &&
    ticketService.includes('->lockForUpdate()') &&
    ticketService.includes('(int) $lockedTicket->user_id !== (int) $userId'),
  'User/Telegram ticket replies are not serialized against ticket closure.'
);

console.log('Telegram unique binding migration gates and non-negative coupon/VIP composition verified.');
