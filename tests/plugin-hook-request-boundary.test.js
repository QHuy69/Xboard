const assert = require('assert');
const fs = require('fs');

const middleware = fs.readFileSync('app/Http/Middleware/InitializePlugins.php', 'utf8');
const manager = fs.readFileSync('app/Services/Plugin/PluginManager.php', 'utf8');
const smoke = fs.readFileSync('tests/smoke-plugin-admin-config.php', 'utf8');

const reset = middleware.indexOf('HookManager::reset();');
const prepare = middleware.indexOf('$this->pluginManager->prepareForRequest();');
const initialize = middleware.indexOf('$this->pluginManager->initializeEnabledPlugins();');
assert(reset >= 0 && reset < prepare && prepare < initialize,
  'request hooks must reset and re-arm before enabled plugins boot');
assert(manager.includes('public function prepareForRequest(): void')
  && manager.includes('$this->pluginsInitialized = false;'),
  'a warmed Octane PluginManager is not re-armed per request');
assert(smoke.includes('A stale Octane hook was duplicated into the next request.')
  && smoke.includes('A disabled plugin hook remained active in the next request.'),
  'PHP smoke does not cover duplicate and disabled stale hooks');

console.log('Plugin hooks reset and reinitialize at the Octane request boundary.');
