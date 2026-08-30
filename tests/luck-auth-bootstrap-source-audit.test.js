const assert = require('assert');
const fs = require('fs');

const patcher = fs.readFileSync('app/Services/LuckThemeAssetPatcher.php', 'utf8');
const smoke = fs.readFileSync('tests/smoke-luck-theme-patches.php', 'utf8');

for (const marker of [
  'const restoredUser = JSON.parse(savedUser);',
  'profileStatus === 401 || profileStatus === 403',
  'profileError.luckAuthStage = "profile"',
  'isAuthFailure ? "auth"',
  'profileError.response ? "server" : "network"',
  'if (authStore.isLoading) return;',
  'if (!authStore.isAuthenticated)',
  'if (registerSubmitting.value || authStore.isLoading) return;',
  'registerSubmitting.value = false;',
  'router.currentRoute.value.path !== "/dashboard"',
]) {
  assert(patcher.includes(marker), `Luck auth patch is missing: ${marker}`);
}

const loginInvariant = patcher.indexOf('const profileError = new Error("Authenticated user profile was not initialized")');
const loginSuccessAfterInvariant = patcher.indexOf('customMessage.loginSuccess();', loginInvariant);
assert(loginInvariant >= 0 && loginSuccessAfterInvariant > loginInvariant,
  'Login success must only render after the profile bootstrap invariant.');

const registerInvariant = patcher.indexOf('const profileError = new Error("Registered user profile was not initialized")');
const registerSuccessAfterInvariant = patcher.indexOf('customMessage.registerSuccess();', registerInvariant);
assert(registerInvariant >= 0 && registerSuccessAfterInvariant > registerInvariant,
  'Registration success must only render after the profile bootstrap invariant.');

for (const regression of [
  'Luck login patch must be idempotent.',
  'Luck registration patch must be idempotent.',
  'Luck shared-auth patch must be idempotent.',
  'str_contains($sharedAuth, \'user.value = { id: 0\')',
]) {
  assert(smoke.includes(regression), `Auth regression fixture is missing: ${regression}`);
}

console.log('Luck login/register bootstrap, error classification and submit locks are guarded.');
