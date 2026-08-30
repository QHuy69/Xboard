const assert = require('assert');
const fs = require('fs');

const bundle = fs.readFileSync('public/assets/admin/assets/index-CEIYH7i8.js', 'utf8');
const paymentService = fs.readFileSync('app/Services/PaymentService.php', 'utf8');
const pluginConfigService = fs.readFileSync('app/Services/Plugin/PluginConfigService.php', 'utf8');
const pluginManager = fs.readFileSync('app/Services/Plugin/PluginManager.php', 'utf8');

assert(
  bundle.includes('onClick:()=>s(e.code),disabled:a,className:"h-7 px-2 text-xs"'),
  'The configuration button must remain usable while an installed plugin is disabled.'
);
assert(
  !bundle.includes('onClick:()=>s(e.code),disabled:!e.is_enabled||a,className:"h-7 px-2 text-xs"'),
  'The old disabled-plugin configuration deadlock is still present.'
);
assert(
  bundle.includes('w-[calc(100vw-2rem)] max-h-[90vh] overflow-hidden sm:max-w-2xl'),
  'The plugin configuration dialog is not using the responsive wide layout.'
);
assert(
  bundle.includes('flex flex-wrap items-center justify-end gap-2'),
  'Plugin action buttons must wrap cleanly on narrow screens.'
);
assert(
  bundle.includes('children:[e("config.title")," ",E?.find(e=>e.code===o)?.name]'),
  'The Vietnamese configuration dialog title is still in the wrong word order.'
);

assert(
  paymentService.includes("!hash_equals((string) ($payment['payment'] ?? ''), (string) $this->method)"),
  'Payment id/UUID records can still be combined with a different gateway method.'
);
assert(
  paymentService.includes("'type' => $isSensitive ? 'password' : $type"),
  'Conventionally named legacy secrets must render as password controls.'
);
assert(
  pluginConfigService.includes('return DB::transaction(function () use ($pluginCode, $config, $defaultConfig): bool')
    && pluginConfigService.includes('->lockForUpdate()')
    && pluginConfigService.includes('if ($plugin->is_enabled)')
    && pluginConfigService.includes('$this->pluginManager->validateActivationConfig($pluginCode, $values)'),
  'Plugin config saves must lock the installed row and revalidate enabled plugins.'
);
assert(
  pluginManager.includes('public function validateActivationConfig(string $pluginCode, array $config): void')
    && pluginManager.includes('$candidate = clone $plugin')
    && pluginManager.includes('$candidate->validateActivation()'),
  'Enabled-plugin candidate validation must not mutate the active request-scoped plugin instance.'
);

console.log('Disabled-plugin configuration access and responsive plugin layout verified.');
