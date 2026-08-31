const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const migration = read('database/migrations/2026_08_31_000005_add_coinpayments_checkout_snapshot.php');
const ciSmoke = read('.docker/ci-smoke.sh');
const deploy = read('.docker/deploy-production.sh');
const migrationName = '2026_08_31_000005_add_coinpayments_checkout_snapshot';

assert(
  migration.includes('assertReadyProviderMetadata($checkout)')
    && migration.includes('assertReadyCheckoutsHaveProviderMetadata();')
    && migration.includes("READY checkout is missing provider_invoice_id")
    && migration.includes("READY checkout is missing provider_expires_at")
    && migration.includes('assertNoDuplicateProviderInvoiceIds()')
    && migration.includes('order_payment_checkout_provider_invoice_unique'),
  'Migration 000005 does not fail closed for incomplete legacy READY provider metadata.',
);

for (const releaseGate of [ciSmoke, deploy]) {
  assert(
    releaseGate.includes(`${migrationName}.*Ran`),
    'A release gate does not require migration 000005 to be recorded as Ran.',
  );
  assert(
    releaseGate.includes('verify_coinpayments_checkout_schema')
      && releaseGate.includes("schema_state\" != '5:3:0'")
      && releaseGate.includes('order_payment_checkout_provider_invoice_unique'),
    'A release gate does not verify the complete migration 000005 schema and READY invariant.',
  );
}

assert(
  deploy.includes('Stopping the failed release before restoring the pre-deploy database backup.')
    && deploy.includes('XBOARD_DB_BACKUP_NAME="$backup_name"')
    && deploy.includes('database.sqlite.restore-${XBOARD_DB_RESTORE_TOKEN}')
    && deploy.includes('cmp -s "$backup_path" "$live_path"'),
  'Production rollback does not restore and verify the exact pre-deploy SQLite backup.',
);
assert(
  deploy.includes("rollback_actual_image=\"$(docker inspect --format '{{.Image}}'")
    && deploy.includes('Rollback container readiness failed')
    && deploy.includes('/api/v1/guest/comm/config')
    && deploy.includes('Rollback database integrity verification failed'),
  'Production rollback does not verify the captured image, health, endpoint, and restored database.',
);

console.log('CoinPayments release safety source audit passed.');
