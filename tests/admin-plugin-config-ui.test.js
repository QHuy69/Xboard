const assert = require('assert');
const { execFileSync } = require('child_process');
const fs = require('fs');
const { patchAdminBundle } = require('../scripts/patch-admin-user-locale');

const bundle = fs.readFileSync('public/assets/admin/assets/index-CEIYH7i8.js', 'utf8');
const paymentService = fs.readFileSync('app/Services/PaymentService.php', 'utf8');
const pluginConfigService = fs.readFileSync('app/Services/Plugin/PluginConfigService.php', 'utf8');
const pluginManager = fs.readFileSync('app/Services/Plugin/PluginManager.php', 'utf8');

const cleanAdminBundle = execFileSync(
  'git',
  ['-C', 'public/assets/admin', 'show', 'HEAD:assets/index-CEIYH7i8.js'],
  { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }
);
const patchedCleanAdminBundle = patchAdminBundle(cleanAdminBundle);
assert(
  patchedCleanAdminBundle.includes('drawerStyle:{width:"100%",maxWidth:"none",maxHeight:"calc(100dvh - 0.5rem)"'),
  'A clean admin submodule checkout cannot reproduce the mobile Drawer patch.'
);
assert.strictEqual(
  patchAdminBundle(patchedCleanAdminBundle),
  patchedCleanAdminBundle,
  'The admin bundle patch is not byte-idempotent.'
);

assert(
  bundle.includes('onClick:()=>s(e.code),disabled:a,className:"h-7 px-2 text-xs"'),
  'The configuration button must remain usable while an installed plugin is disabled.'
);
assert(
  !bundle.includes('onClick:()=>s(e.code),disabled:!e.is_enabled||a,className:"h-7 px-2 text-xs"'),
  'The old disabled-plugin configuration deadlock is still present.'
);
assert(
  bundle.includes('drawerClassName:t,drawerStyle:o,children:n,...i},r)=>utt()?Q.jsx(stt,{ref:r,className:Im("flex max-h-[90vh] flex-col",t),style:o,children:n}'),
  'ResponsiveDialogContent must forward a dedicated style object to the mobile Drawer branch.'
);
assert(
  bundle.includes('drawerClassName:"max-h-[90vh] overflow-hidden",drawerStyle:{width:"100%",maxWidth:"none",maxHeight:"calc(100dvh - 0.5rem)",display:"flex",flexDirection:"column",overflow:"hidden"'),
  'The plugin configuration Drawer is not viewport-bound for mobile browser chrome and keyboards.'
);
assert(
  bundle.includes('style:{width:"calc(100vw - 2rem)",maxWidth:"42rem",maxHeight:"calc(100dvh - 2rem)",display:"flex",flexDirection:"column",overflow:"hidden"'),
  'The plugin configuration dialog is not viewport-bound on desktop and mobile.'
);
assert(
  bundle.includes('style:{minHeight:0,flex:"1 1 auto"},children:[Q.jsx("div",{className:"min-w-0 flex-1 overflow-auto",style:{minHeight:0,maxHeight:"calc(100dvh - 14rem)",overflowY:"auto",overflowX:"hidden"'),
  'The plugin configuration fields are not isolated in a viewport-bound scroll region.'
);
assert(
  bundle.includes('style:{position:"sticky",bottom:0,zIndex:1,flex:"0 0 auto",background:"hsl(var(--background))"}'),
  'The plugin configuration action footer must remain visible while fields scroll.'
);
assert(
  !bundle.includes('Q.jsx(Gqt,{className:"max-h-[65vh] min-w-0 flex-1 overflow-auto"'),
  'The ineffective post-build max-height utility is still controlling the config body.'
);
assert(
  bundle.includes('style:{minWidth:0,flex:"1 1 auto",overflowWrap:"anywhere"}')
    && bundle.includes('style:{minWidth:0,overflowWrap:"anywhere"},children:Object.entries(t)'),
  'Long localized plugin labels and secret-setting names can still clip the mobile Drawer.'
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
