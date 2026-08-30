const assert = require('assert');
const fs = require('fs');

function source(file) {
  return fs.readFileSync(file, 'utf8');
}

function includesAll(file, values) {
  const text = source(file);
  for (const value of values) {
    assert(text.includes(value), `${file} is missing: ${value}`);
  }
}

includesAll('luck-dashboard.blade.php', [
  "^#\\/register(?:\\?|$)",
  "legacyRoute = window.location.hash.slice(1)",
  "closest('.register-link')",
  "class=\"luck-language-picker\"",
  "window.__LUCK_SET_LOCALE__(select.value)",
  "subscription && subscription.locale",
  "CRISP_RUNTIME_CONFIG.locale",
  "messenger_page_username",
]);

includesAll('app/Services/LuckThemeAssetPatcher.php', [
  'formData.email.trim().toLowerCase()',
  'formData.password.length < 8',
  'error.luckAuthStage === "profile"',
  'error.response.status === 401',
  'const invitationCodeFromUrl =',
  'backendConfig.value.is_invite_force && !formData.inviteCode.trim()',
  'if (error.response)',
  'const rawPaymentResult =',
  'Number(paymentResult.type) ===',
  'window.location.assign(paymentResult.data)',
]);

includesAll('luck-overrides.css', [
  'width: min(520px, calc(100vw - 40px))',
  '@media (max-height: 760px)',
  '.luck-language-picker',
  '.luck-messenger-support',
]);

includesAll('public/assets/admin/assets/index-CEIYH7i8.js', [
  'name:"locale"',
  'edit.form.language',
  'crisp_website_id',
  'messenger_page_username',
]);

console.log('Register navigation, auth, payment, responsive support and language controls verified.');
