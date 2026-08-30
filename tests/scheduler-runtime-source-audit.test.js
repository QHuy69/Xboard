const assert = require('assert');
const fs = require('fs');

function read(file) {
  return fs.readFileSync(file, 'utf8');
}

function includesAll(file, needles) {
  const source = read(file);
  for (const needle of needles) {
    assert(source.includes(needle), `${file} is missing scheduler runtime guard: ${needle}`);
  }
}

includesAll('.docker/supervisor/supervisord.conf', [
  '[program:scheduler]',
  'artisan schedule:work --no-interaction',
  'autostart=%(ENV_ENABLE_SCHEDULER)s',
  'stopasgroup=true',
]);

includesAll('.docker/healthcheck.sh', [
  'is_enabled ENABLE_SCHEDULER',
  "has_process 'artisan schedule:work'",
]);

includesAll('Dockerfile', [
  'ENABLE_SCHEDULER=true',
]);

includesAll('compose.production.yaml', [
  'ENABLE_SCHEDULER: "true"',
  'OCTANE_SCHEDULER_TICK: "false"',
]);

for (const composeFile of ['compose.sample.yaml', 'compose.host.sample.yaml', 'compose.1panel.sample.yaml']) {
  includesAll(composeFile, [
    'ENABLE_SCHEDULER=true',
    'OCTANE_SCHEDULER_TICK=false',
  ]);
}

includesAll('compose.split.sample.yaml', [
  'scheduler:',
  'ENABLE_SCHEDULER: "true"',
  'ENABLE_SCHEDULER: "false"',
]);

includesAll('config/octane.php', [
  "'scheduler_tick' => filter_var(env('OCTANE_SCHEDULER_TICK', false), FILTER_VALIDATE_BOOLEAN)",
]);

includesAll('app/Providers/OctaneServiceProvider.php', [
  "if (!config('octane.scheduler_tick', false))",
  "Artisan::call('schedule:run')",
]);

includesAll('app/Console/Kernel.php', [
  "name('scheduler-heartbeat')->everyMinute()->onOneServer()",
  'registerPluginSchedules($schedule)',
]);

includesAll('plugins-core/Telegram/Plugin.php', [
  "name('telegram-node-group-report')",
  "name('telegram-database-backup')",
  '->onOneServer()',
  '->withoutOverlapping(',
]);

const kernel = read('app/Console/Kernel.php');
const scheduleMethod = kernel.slice(kernel.indexOf('protected function schedule'), kernel.indexOf('protected function commands'));
const heartbeatEvent = scheduleMethod.indexOf('$schedule->call(static function');
const heartbeatWrite = scheduleMethod.indexOf('Cache::put(', heartbeatEvent);
assert(heartbeatEvent >= 0 && heartbeatWrite > heartbeatEvent,
  'Scheduler heartbeat is still written eagerly while merely listing the schedule');

console.log('Dedicated scheduler wiring and Telegram callback guards passed source audit.');
