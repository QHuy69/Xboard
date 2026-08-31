const assert = require('assert');
const fs = require('fs');

const read = (file) => fs.readFileSync(file, 'utf8');
const migrationName = '2026_09_01_000001_add_unique_telegram_id_to_users';
const migration = read(`database/migrations/${migrationName}.php`);
const orderService = read('app/Services/OrderService.php');

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

console.log('Telegram unique binding migration gates and non-negative coupon/VIP composition verified.');
