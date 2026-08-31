const assert = require('assert');
const fs = require('fs');

function read(file) {
  return fs.readFileSync(file, 'utf8');
}

function includesAll(file, needles) {
  const source = read(file);
  for (const needle of needles) {
    assert(source.includes(needle), `${file} is missing reseller-role contract: ${needle}`);
  }
}

includesAll('database/migrations/2026_08_31_120000_add_is_reseller_to_users.php', [
  "Schema::hasColumn('v2_user', 'is_reseller')",
  "$table->boolean('is_reseller')",
  '->default(false)',
  "private const RESELLER_OWNERSHIP_INDEX = 'v2_user_referral_roles_id_idx'",
  "Schema::hasIndex('v2_user', self::RESELLER_OWNERSHIP_INDEX)",
  "['invite_user_id', 'is_admin', 'is_staff', 'is_reseller', 'id']",
  '$table->dropIndex(self::RESELLER_OWNERSHIP_INDEX)',
  "$table->dropColumn('is_reseller')"
]);

const migration = read('database/migrations/2026_08_31_120000_add_is_reseller_to_users.php');
assert(!/boolean\('is_reseller'\)[\s\S]{0,120}->index\(\)/.test(migration), 'is_reseller must not use a standalone boolean index');

for (const releaseGate of ['.docker/ci-smoke.sh', '.docker/deploy-production.sh']) {
  const gate = read(releaseGate);
  assert(
    gate.includes('2026_08_31_120000_add_is_reseller_to_users.*Ran'),
    `${releaseGate} must fail closed unless the reseller-role migration has run`
  );
  assert(gate.includes("grep -aFq 'name:\"is_reseller\"'"),
    `${releaseGate} must verify the served admin bundle contains the reseller switch`);
}

includesAll('app/Models/User.php', [
  "'is_reseller' => 'boolean'"
]);

includesAll('app/Http/Requests/Admin/UserUpdate.php', [
  "'invite_user_email' => 'nullable|email:strict'",
  "'is_reseller' => 'boolean'",
  "'is_reseller.boolean'"
]);

includesAll('app/Http/Requests/Admin/UserFetch.php', [
  "'filter.*.id' => ['required', 'string', Rule::in(self::FILTERABLE_FIELDS)]",
  "'filter.*.value' => ['present']",
  "'sort.*.id' => ['required', 'string', Rule::in(self::SORTABLE_FIELDS)]",
  "HookManager::filter('admin.user.fetch.rules'",
  "__('Filter field is required')",
  "__('Invalid sort direction')",
  "'is_reseller'"
]);
assert(!/[\u3400-\u9fff]/.test(read('app/Http/Requests/Admin/UserFetch.php')),
  'The newly activated admin user fetch validator must not return hardcoded Chinese messages');

includesAll('app/Http/Controllers/V2/Admin/UserController.php', [
  'use App\\Http\\Requests\\Admin\\UserFetch',
  'public function fetch(UserFetch $request)',
  "$user['is_reseller'] = (bool) ($user['is_reseller'] ?? false)",
  "array_key_exists('invite_user_email', $params)",
  "unset($params['invite_user_email'])",
  'referralWouldCreateCycle',
  "array_key_exists('invite_user_id', $params) && $params['invite_user_id'] !== null",
  'updateWithReferralLocks($user, $params, $request)',
  'DB::transaction(function () use ($user, $params, $request): string',
  'sort($lockIds, SORT_NUMERIC)',
  'foreach ($lockIds as $lockId)',
  '->lockForUpdate()',
  'lockedReferralWouldCreateCycle',
  '}, 3);',
  "__('The referrer does not exist')",
  "__('The referral relationship would create a cycle')"
]);

const userController = read('app/Http/Controllers/V2/Admin/UserController.php');
const filteredParamsAt = userController.indexOf("HookManager::filter('admin.user.update.params'");
const lockedUpdateAt = userController.indexOf('updateWithReferralLocks($user, $params, $request)');
const lockingHelperAt = userController.indexOf('private function updateWithReferralLocks');
const stableLockOrderAt = userController.indexOf('sort($lockIds, SORT_NUMERIC)', lockingHelperAt);
const pairLockAt = userController.indexOf('->lockForUpdate()', stableLockOrderAt);
const lockedCycleCheckAt = userController.indexOf('lockedReferralWouldCreateCycle(', pairLockAt);
const beforeHookAt = userController.indexOf("HookManager::call('admin.user.update.before'", lockedCycleCheckAt);
const persistedUpdateAt = userController.indexOf('$lockedUser->update($params)', beforeHookAt);
const lockingHelper = userController.slice(lockingHelperAt, userController.indexOf('// Export users to CSV.', lockingHelperAt));
const rowLockCount = (lockingHelper.match(/->lockForUpdate\(\)/g) || []).length;

assert(filteredParamsAt >= 0 && filteredParamsAt < lockedUpdateAt,
  'The authoritative referral check must use the final plugin-filtered update payload');
assert(lockingHelperAt >= 0 && lockingHelperAt < stableLockOrderAt && stableLockOrderAt < pairLockAt,
  'Target and direct referrer locks must be acquired in a stable numeric ID order');
assert(pairLockAt < lockedCycleCheckAt && lockedCycleCheckAt < beforeHookAt && beforeHookAt < persistedUpdateAt,
  'Cycle validation must run under row locks before hooks and persistence');
assert(rowLockCount >= 2,
  'Both the direct target/referrer pair and each subsequently traversed ancestor must be row-locked');

includesAll('tests/Feature/AdminResellerRoleTest.php', [
  'test_admin_can_filter_and_sort_users_by_reseller_role',
  'test_non_admin_and_reseller_only_accounts_cannot_change_roles',
  'test_referral_owner_cannot_be_the_user_itself',
  'test_two_node_referral_cycle_is_rejected_without_changing_owner',
  'test_three_node_referral_cycle_is_rejected_without_changing_owner',
  'test_reseller_schema_has_the_ownership_query_index',
  'test_reseller_migration_up_and_down_are_idempotent'
]);

includesAll('scripts/patch-admin-user-locale.js', [
  'is_reseller:uy().default(!1)',
  'name:"is_reseller"',
  'edit.form.is_reseller'
]);

includesAll('public/assets/admin/assets/index-CEIYH7i8.js', [
  'is_reseller:uy().default(!1)',
  'name:"is_reseller"',
  'edit.form.is_reseller'
]);

const localeFiles = {
  'en-US': 'public/assets/admin/locales/en-US.js',
  'vi-VN': 'public/assets/admin/locales/vi-VN-v3.js',
  'zh-CN': 'public/assets/admin/locales/zh-CN.js',
  'ru-RU': 'public/assets/admin/locales/ru-RU.js'
};

for (const [locale, file] of Object.entries(localeFiles)) {
  const source = read(file);
  const match = source.match(/"is_reseller"\s*:\s*"([^"]+)"/);
  assert(match && match[1].trim(), `${locale} is missing a localized reseller label`);
  if (locale !== 'en-US') {
    assert.notStrictEqual(match[1], 'Is Reseller', `${locale} falls back to the English reseller label`);
  }
}

const backendLocaleFiles = [
  'en-US', 'vi-VN', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'
];
for (const locale of backendLocaleFiles) {
  const messages = JSON.parse(read(`resources/lang/${locale}.json`));
  for (const key of [
    'The referrer does not exist',
    'The referral relationship would create a cycle',
    'Filter field is required',
    'Invalid filter field',
    'Filter value is required',
    'Invalid filter logic',
    'Sort field is required',
    'Invalid sort field',
    'Sort direction is required',
    'Invalid sort direction'
  ]) {
    assert.strictEqual(typeof messages[key], 'string', `${locale} is missing ${key}`);
    assert(messages[key].trim(), `${locale} has an empty ${key} translation`);
  }
}

console.log('Admin reseller role migration, API contract, localized editor switch and compiled assets verified.');
