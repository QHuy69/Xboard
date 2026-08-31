const assert = require('assert');
const fs = require('fs');

const ci = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');

for (const [file, source] of [
  ['.docker/ci-smoke.sh', ci],
  ['.docker/deploy-production.sh', deploy],
]) {
  for (const marker of [
    'verify_telegram_persistence_schema()',
    "WHERE name = 'v2_user_telegram_id_unique'",
    "FROM pragma_index_info('v2_user_telegram_id_unique')",
    ") = 'telegram_id'",
    'HAVING COUNT(*) > 1',
    "pragma_table_info('telegram_webhook_update_receipts')",
    "name = 'id' AND upper(type) = 'INTEGER' AND \\\"notnull\\\" = 1 AND pk = 1",
    "name = 'receipt_hash' AND upper(type) = 'VARCHAR' AND \\\"notnull\\\" = 1 AND pk = 0",
    "name = 'created_at' AND upper(type) = 'DATETIME' AND \\\"notnull\\\" = 1 AND pk = 0",
    "name = 'expires_at' AND upper(type) = 'DATETIME' AND \\\"notnull\\\" = 1 AND pk = 0",
    "WHERE name = 'telegram_webhook_receipt_hash_unique'",
    "FROM pragma_index_info('telegram_webhook_receipt_hash_unique')",
    ") = 'receipt_hash'",
    "WHERE name = 'telegram_webhook_receipt_expiry_idx'",
    "FROM pragma_index_info('telegram_webhook_receipt_expiry_idx')",
    ") = 'expires_at'",
    "if [ \"$schema_state\" != '0:1:4:4:1:1' ]",
    "grep -q '2026_09_01_000002_.*Ran' <<<\"$migration_status\"",
  ]) {
    assert(source.includes(marker), `${file} is missing release database gate: ${marker}`);
  }
  const migrationCheck = source.indexOf("grep -q '2026_09_01_000002_.*Ran'");
  const schemaCall = source.lastIndexOf('verify_telegram_persistence_schema "$');
  assert(migrationCheck >= 0 && schemaCall > migrationCheck,
    `${file} must verify the durable schema after confirming migration 000002 ran`);
}

assert(deploy.includes('verify_no_duplicate_telegram_ids()'),
  'production deploy needs a read-only duplicate Telegram-id preflight');
const preflightCall = deploy.indexOf('verify_no_duplicate_telegram_ids "$current_container"',
  deploy.indexOf('if [ -n "$current_container" ]; then'));
for (const mutation of [
  'timestamp="$(date -u',
  'create_persistent_backup',
  'docker image tag "$previous_image_id"',
  'VACUUM INTO',
  'docker compose -f "$compose_file" pull xboard',
]) {
  const mutationIndex = deploy.indexOf(mutation, deploy.indexOf('if [ -n "$current_container" ]; then'));
  assert(preflightCall >= 0 && mutationIndex > preflightCall,
    `duplicate Telegram-id preflight must run before production mutation: ${mutation}`);
}
assert(deploy.includes('Refusing deployment before mutation: found $duplicate_count duplicate non-null telegram_id group(s).'),
  'duplicate preflight failure must explain why production was left untouched');

console.log('Telegram deploy preflight, exact unique index and durable receipt migration gates verified.');
