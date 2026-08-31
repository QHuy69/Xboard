const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const sepay = read('plugins-core/Sepay/Plugin.php');
const guestPaymentController = read('app/Http/Controllers/V1/Guest/PaymentController.php');

assert(
  /'custom_result'\s*=>\s*\[\s*'success'\s*=>\s*true\s*\]/.test(sepay),
  'A successful SePay webhook must return the JSON acknowledgement {"success":true}.',
);
assert(
  /'ack_only'\s*=>\s*true/.test(sepay),
  'An authenticated ignored SePay event must use the acknowledgement-only response path.',
);
assert(
  guestPaymentController.includes("return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');"),
  'The guest payment boundary no longer preserves each gateway custom response.',
);
assert(
  guestPaymentController.includes("($verify['ack_only'] ?? false) === true")
    && guestPaymentController.includes("array_key_exists('custom_result', $verify)"),
  'The guest payment boundary must return acknowledgement-only JSON before order handling.',
);

console.log('SePay webhook response contract source audit passed.');
