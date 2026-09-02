const assert = require('assert');
const fs = require('fs');

const migration = fs.readFileSync(
  'database/migrations/2026_09_02_000003_create_usdt_direct_payment_tables.php',
  'utf8'
);
const ci = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');
const orderService = fs.readFileSync('app/Services/OrderService.php', 'utf8');

for (const marker of [
  'usdt_invoice_amount_assignment_unique',
  'usdt_transfer_chain_identity_unique',
  'usdt_scan_cursor_source_unique',
]) {
  assert(migration.includes(marker), `USDT migration is missing ${marker}`);
}

for (const [file, source] of [
  ['.docker/ci-smoke.sh', ci],
  ['.docker/deploy-production.sh', deploy],
]) {
  for (const marker of [
    '2026_09_02_000003_create_usdt_direct_payment_tables',
    "25:18:12:4:1:1",
    "code = 'usdt_direct'",
    "1.0.0:0",
  ]) {
    assert(source.includes(marker), `${file} is missing USDT release gate ${marker}`);
  }
}

assert(deploy.includes("'1.0.0:0'|'1.0.0:1'"), 'production must preserve the admin-controlled plugin state');
assert(ci.includes("expected 1.0.0:0 on a fresh install"), 'fresh-install smoke must prove USDT stays disabled before configuration');
assert(orderService.includes('$transferWasExisting = $inserted === 0;'),
  'insertOrIgnore loser must be treated as existing evidence and conflict-checked');
assert(orderService.includes("'manual_review_reason' => 'additional_transfer_after_settlement'"),
  'extra payments must be isolated without downgrading a confirmed invoice');

console.log('USDT Direct release safety source audit passed.');
