const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const plugin = read('plugins-core/Telegram/Plugin.php');
const ticketService = read('app/Services/TicketService.php');
const resellerService = read('app/Services/TelegramResellerService.php');
const resellerSupportJob = read('app/Jobs/SendTelegramResellerSupportNotificationJob.php');
const telegramService = read('app/Services/TelegramService.php');
const config = JSON.parse(read('plugins-core/Telegram/config.json'));
const readme = read('plugins-core/Telegram/README.md');

function between(source, start, end) {
  const startIndex = source.indexOf(start);
  assert(startIndex >= 0, `Missing source marker: ${start}`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert(endIndex > startIndex, `Missing source marker after ${start}: ${end}`);
  return source.slice(startIndex, endIndex);
}

function includesAll(source, needles, contract) {
  for (const needle of needles) {
    assert(source.includes(needle), `${contract} is missing: ${needle}`);
  }
}

assert.strictEqual(config.version, '2.3.1', 'One-bot support inbox must remain present in Telegram plugin v2.3.1.');
for (const removedConfig of [
  'enable_reseller_support_chat',
  'reseller_support_chat_id',
  'reseller_support_group_id',
  'reseller_support_bot_token',
  'reseller_telegram_ids',
  'reseller_allowed_telegram_ids',
]) {
  assert(!Object.prototype.hasOwnProperty.call(config.config, removedConfig),
    `One-bot support must not require legacy config: ${removedConfig}`);
  assert(!plugin.includes(`getConfig('${removedConfig}'`),
    `Runtime still reads removed support config: ${removedConfig}`);
}
assert.deepStrictEqual(
  Object.keys(config.config).filter((key) => /support/i.test(key)),
  [],
  'Support chat must not add a toggle, destination, group, chat id, or second-bot setting.'
);
assert(!Object.keys(config.config).some((key) => /(?:bot.*token|token.*bot)/i.test(key)),
  'The plugin schema exposes a second bot-token field instead of using the default bot.');
assert(readme.includes('**2.3.1**')
  && readme.includes('cùng bot mặc định')
  && readme.includes('không cần bot thứ hai, nhóm hỗ trợ hoặc cấu hình chat ID riêng')
  && readme.includes('Chỉ tài khoản đã liên kết có cờ `is_admin`'),
  'README does not document the one-bot, linked-admin-only support model.');

includesAll(plugin, [
  "private const SUPPORT_TICKET_SUBJECT = '[Telegram reseller support]';",
  'private const SUPPORT_MESSAGE_MAX_LENGTH = 1000;',
  'private const SUPPORT_ADMIN_STATE_TTL_SECONDS = 900;',
  'private const SUPPORT_CALLBACK_TTL_SECONDS = 900;',
  'private const SUPPORT_INBOX_PAGE_SIZE = 5;',
  'private const SUPPORT_HISTORY_LIMIT = 6;',
  "'reseller:support:open'",
  "'text' => $this->text('button_support', $locale)",
  'if ($this->adminSupportState($msg)) {',
  '$this->handleSupportAdminInput($msg);',
], 'One-bot support entry points and bounded state');

const messageBoundary = between(plugin, 'public function handleMessage', 'protected function handleCommandMessage');
includesAll(messageBoundary, [
  '$actorId = $this->actorId($msg);',
  "'telegram:actor:' . hash('sha256', $actorId)",
  'self::ACTOR_LOCK_TTL_SECONDS',
  'if (!$actorLock->get())',
  "'callback_query' => $this->handleCallback($msg)",
  "'reply_message' => $this->handleReplyMessage($msg)",
  'default => $this->handleCommandMessage($msg)',
  'if ($actorLock)',
  '$actorLock->release()',
], 'Actor-wide command/callback/reply serialization');
const actorLockAcquire = messageBoundary.indexOf("'telegram:actor:' . hash('sha256', $actorId)");
const actorDispatch = messageBoundary.indexOf("'callback_query' => $this->handleCallback($msg)");
const actorLockRelease = messageBoundary.indexOf('$actorLock->release()');
assert(actorLockAcquire >= 0 && actorLockAcquire < actorDispatch && actorDispatch < actorLockRelease,
  'Telegram actor lock does not cover the whole command/callback/reply dispatch boundary.');

const legacyReplyRouting = between(plugin, 'protected function handleReplyMessage', 'protected function handleCallback');
const legacyReplyMatch = legacyReplyRouting.indexOf('if (preg_match($regex, $msg->reply_text, $matches))');
const legacyClearReseller = legacyReplyRouting.indexOf('$this->clearResellerState($msg);', legacyReplyMatch);
const legacyClearAdmin = legacyReplyRouting.indexOf('$this->clearAdminSupportState($msg);', legacyClearReseller);
const legacyHandler = legacyReplyRouting.indexOf('call_user_func($handler, $msg, $matches);', legacyClearAdmin);
assert(legacyReplyMatch >= 0
  && legacyReplyMatch < legacyClearReseller
  && legacyClearReseller < legacyClearAdmin
  && legacyClearAdmin < legacyHandler,
  'A legacy ticket-reply handler can run while reseller/admin conversational state remains armed.');

const startMenu = between(plugin, 'public function handleStartCommand', 'public function handleBindCommand');
includesAll(startMenu, [
  'if ($this->isAdmin($msg)) {',
  "'text' => $this->text('button_support_inbox', $locale)",
  "'callback_data' => 'support:inbox:1'",
], 'Linked administrator inbox button');

const callbacks = between(plugin, 'protected function handleCallback', 'public function handleStartCommand');
includesAll(callbacks, [
  "preg_match('/^support:inbox:([1-9][0-9]*)$/', $command, $matches)",
  "preg_match('/^support:view:([a-f0-9]{32})$/', $command, $matches)",
  "preg_match('/^support:reply:([a-f0-9]{32})$/', $command, $matches)",
  "$command === 'support:cancel'",
  '$this->clearAdminSupportState($msg);',
], 'Admin inbox list/view/reply button routing');
const clearResellerBeforeSupport = callbacks.indexOf("if (str_starts_with($command, 'support:')) {");
const firstSupportRoute = callbacks.indexOf("preg_match('/^support:inbox:");
assert(clearResellerBeforeSupport >= 0 && clearResellerBeforeSupport < firstSupportRoute,
  'A support:* callback can leave reseller coupon/support input state active.');
const supportSwitch = callbacks.slice(clearResellerBeforeSupport, callbacks.indexOf('} elseif', clearResellerBeforeSupport));
assert(supportSwitch.includes('$this->clearResellerState($msg);'),
  'The support:* context switch does not actually clear reseller state.');
const clearAdminBeforeReseller = callbacks.indexOf("str_starts_with($command, 'reseller:')");
const firstResellerRoute = callbacks.indexOf("$command === 'reseller:menu'");
assert(clearAdminBeforeReseller >= 0 && clearAdminBeforeReseller < firstResellerRoute,
  'A reseller:* callback can leave admin reply-input state active.');
const resellerSwitch = callbacks.slice(clearAdminBeforeReseller, callbacks.indexOf('} elseif', clearAdminBeforeReseller));
assert(resellerSwitch.includes('$this->clearAdminSupportState($msg);'),
  'The reseller:* context switch does not actually clear admin support state.');
const actionSwitchStart = callbacks.indexOf("str_starts_with($command, 'action:')");
const firstActionRoute = callbacks.indexOf("$command === 'action:menu'");
const actionSwitch = callbacks.slice(actionSwitchStart, firstActionRoute);
assert(actionSwitchStart >= 0 && actionSwitchStart < firstActionRoute
  && actionSwitch.includes('$this->clearResellerState($msg);')
  && actionSwitch.includes('$this->clearAdminSupportState($msg);'),
  'A general action:* navigation callback can leave either privileged input state armed.');

const resellerMenu = between(plugin, 'public function handleResellerCommand', 'protected function startReseller');
assert(resellerMenu.includes("'callback_data' => 'reseller:support:open'"),
  'The reseller support button is missing without a configured group.');
assert(resellerMenu.includes('$this->clearAdminSupportState($msg);')
  && resellerMenu.indexOf('$this->clearAdminSupportState($msg);')
    < resellerMenu.indexOf('$this->clearResellerState($msg);'),
  'Opening the reseller menu does not clear a stale admin support reply state first.');
assert(!/supportChatId|reseller_support_chat_id|enable_reseller_support_chat/.test(resellerMenu),
  'The reseller menu still depends on a configured support group/chat.');

const userSupport = between(plugin, 'protected function openResellerSupport', 'protected function showResellerPlans');
includesAll(userSupport, [
  'if (!$this->privateChat($msg))',
  '$actor = $this->resellerActor($msg)',
  'if (!$this->hasAvailableSupportAdmin($actor))',
  "'step' => $ticket ? 'support_active' : 'support_waiting'",
  '$this->deliveryKey($msg) === null',
  'RateLimiter::tooManyAttempts($rateKey, self::SUPPORT_RATE_ATTEMPTS)',
  'mb_strlen($message) > self::SUPPORT_MESSAGE_MAX_LENGTH',
  '(new TicketService())->createTicket(',
  '(new TicketService())->reply($ticket, $message, (int) $actor->id)',
  "HookManager::call($hookName, $ticket)",
  "'ticket.create.after'",
  "'ticket.reply.user.after'",
  "where('user_id', $actor->id)",
  "where('subject', self::SUPPORT_TICKET_SUBJECT)",
  '$ticket->status = Ticket::STATUS_CLOSED;',
], 'Private, role-checked, persistent reseller support flow');
assert(userSupport.indexOf('(new TicketService())->createTicket(')
  < userSupport.indexOf('HookManager::call($hookName, $ticket)'),
  'Support notification is emitted before the ticket is durable.');
const supportInput = between(plugin, 'protected function handleResellerSupportInput', 'protected function showResellerPlans');
const durableTicket = supportInput.indexOf("$hookName = 'ticket.create.after';");
const activateState = supportInput.indexOf("$currentState['step'] = 'support_active';", durableTicket);
const attachTicket = supportInput.indexOf("$currentState['ticket_id'] = (int) $ticket->id;", activateState);
const persistState = supportInput.indexOf('$this->setResellerState($msg, $currentState);', attachTicket);
const notifyFanout = supportInput.indexOf('HookManager::call($hookName, $ticket);', persistState);
assert(durableTicket >= 0 && durableTicket < activateState && activateState < attachTicket
  && attachTicket < persistState && persistState < notifyFanout,
  'Durable support ticket identity is not persisted into actor state before notification fanout.');

const availableAdmin = between(plugin, 'protected function hasAvailableSupportAdmin', 'protected function supportTicketForActor');
includesAll(availableAdmin, [
  "->where('is_admin', true)",
  "->whereNotNull('telegram_id')",
  "->where('id', '!=', $requester->id)",
  '->exists()',
], 'Bound support-admin availability');
assert(!/group|chat_id|supportChatId|getConfig/.test(availableAdmin),
  'CTV support availability still requires a configured group or chat id.');

const notification = between(plugin, 'protected function sendSupportTicketNotify', 'public function deliverQueuedSupportNotification');
includesAll(notification, [
  "->where('is_admin', true)",
  "->whereNotNull('telegram_id')",
  "->where('id', '!=', $user->id)",
  "->pluck('id')",
  'foreach ($adminIds as $adminId)',
  'SendTelegramResellerSupportNotificationJob::dispatch(',
  '(int) $adminId',
  '(int) $ticket->id',
  '(int) $message->id',
], 'Identifier-only notification dispatch to every currently bound administrator');
assert(!notification.includes('$this->telegramService->sendMessage('),
  'Admin support notification still performs synchronous Telegram HTTP inside the webhook request.');
for (const forbiddenPayload of [
  '$admin->telegram_id',
  'support_admin_notification',
  "'markdown'",
  'inline_keyboard',
  'issueSupportCallback(',
]) {
  assert(!notification.includes(forbiddenPayload),
    `Support notification dispatch captures stale or sensitive delivery data: ${forbiddenPayload}`);
}

const queuedDelivery = between(plugin, 'public function deliverQueuedSupportNotification', 'protected function showSupportInbox');
includesAll(queuedDelivery, [
  "->whereKey($adminUserId)",
  "->where('is_admin', true)",
  "->whereNotNull('telegram_id')",
  "->whereKey($ticketId)",
  "->where('subject', self::SUPPORT_TICKET_SUBJECT)",
  "->where('status', Ticket::STATUS_OPENING)",
  "->where('user_id', '!=', $adminUserId)",
  "->whereHas('user', fn ($query) => $query->where('is_reseller', true))",
  "->whereKey($ticketMessageId)",
  "->where('user_id', $ticket?->user_id)",
  "$ticket->messages()->max('id')",
  '(int) $message->id !== $latestMessageId',
  'self::SUPPORT_NOTIFICATION_CALLBACK_TTL_SECONDS,',
  '(string) $admin->telegram_id',
  "'markdown'",
  "['inline_keyboard' => [[[",
  "'callback_data' => 'support:view:' . $token",
], 'Worker-time recipient, ticket, message, role and callback revalidation');
const deliveryGuard = queuedDelivery.indexOf('if (!$admin || !$ticket || !$ticket->user || !$message');
const issueDeliveryToken = queuedDelivery.indexOf('$token = $this->issueSupportCallback(', deliveryGuard);
const sendDelivery = queuedDelivery.indexOf('$this->telegramService->sendMessage(', issueDeliveryToken);
assert(deliveryGuard >= 0 && deliveryGuard < issueDeliveryToken && issueDeliveryToken < sendDelivery,
  'Notification token or content is created before worker-time authorization and freshness checks finish.');
assert(!notification.includes("->where('is_staff', true)"),
  'Staff-only accounts are included in the private admin support notification.');
assert(!notification.includes("'telegram_id' =>") && !notification.includes("'chat_id' =>"),
  'Support notification body/log exposes a Telegram identity.');

const inbox = between(plugin, 'protected function showSupportInbox', 'protected function showSupportConversation');
includesAll(inbox, [
  'if (!$this->privateChat($msg))',
  '$admin = $this->supportAdminActor($msg)',
  "->where('subject', self::SUPPORT_TICKET_SUBJECT)",
  "->where('status', Ticket::STATUS_OPENING)",
  "->where('user_id', '!=', $admin->id)",
  "->whereHas('user', fn ($userQuery) => $userQuery->where('is_reseller', true))",
  '->forPage($page, self::SUPPORT_INBOX_PAGE_SIZE)',
  "$this->issueSupportCallback($admin, 'view', (int) $ticket->id, $page)",
  "'callback_data' => 'support:view:' . $token",
], 'Private linked-admin support inbox');

const conversation = between(plugin, 'protected function showSupportConversation', 'protected function beginSupportAdminReply');
includesAll(conversation, [
  'if (!$this->privateChat($msg))',
  '$admin = $this->supportAdminActor($msg)',
  "$this->consumeSupportCallback($msg, $admin, $token, 'view')",
  '$ticket = $this->supportTicketForAdmin(',
  '(int) $target->id === (int) $admin->id',
  'limit(self::SUPPORT_HISTORY_LIMIT)',
  "$this->issueSupportCallback($admin, 'reply'",
  "$this->issueSupportCallback($admin, 'view'",
  "'callback_data' => 'support:reply:' . $replyToken",
], 'Admin conversation identity, history, refresh and reply controls');
assert(!conversation.includes('SUPPORT_NOTIFICATION_CALLBACK_TTL_SECONDS'),
  'Interactive view/reply callbacks accidentally inherited the one-day notification TTL.');

const beginReply = between(plugin, 'protected function beginSupportAdminReply', 'protected function handleSupportAdminInput');
includesAll(beginReply, [
  'if (!$this->privateChat($msg))',
  '$admin = $this->supportAdminActor($msg)',
  "$this->consumeSupportCallback($msg, $admin, $token, 'reply')",
  '(int) $ticket->user_id === (int) $admin->id',
  "'admin_user_id' => (int) $admin->id",
  "'ticket_id' => (int) $ticket->id",
  "'expected_latest_message_id' => (int) ($ticket->messages()->max('id') ?: 0)",
  "'nonce' => $this->newNonce()",
  "'expires_at' => time() + self::SUPPORT_ADMIN_STATE_TTL_SECONDS",
  '$stateKey = $this->adminSupportKey($msg);',
  'Cache::put($stateKey, $state, self::SUPPORT_ADMIN_STATE_TTL_SECONDS)',
  'Cache::forget($stateKey);',
], 'Admin reply cache state');
const replyStatePut = beginReply.indexOf('Cache::put($stateKey');
const replyPromptSend = beginReply.indexOf(
  "$this->sendMessage($msg, $this->text('support_admin_reply_prompt'",
  replyStatePut
);
const failedPromptCleanup = beginReply.indexOf('Cache::forget($stateKey);', replyPromptSend);
assert(replyStatePut >= 0 && replyStatePut < replyPromptSend && replyPromptSend < failedPromptCleanup,
  'The reply state is not ready before the prompt, or a failed prompt can leave it armed.');

const adminInput = between(plugin, 'protected function handleSupportAdminInput', 'public function handleTicketReply');
includesAll(adminInput, [
  'if (!$this->privateChat($msg) || $this->deliveryKey($msg) === null)',
  '$admin = $this->supportAdminActor($msg)',
  '$this->adminSupportStateForActor($msg, $admin)',
  "'support_admin_reply'",
  '$lock->get()',
  "hash_equals((string) $state['nonce'], $nonce)",
  '$ticket = $this->supportTicketForAdmin(',
  '(int) $target->id === (int) $admin->id',
  '(new TicketService())->replyByAdmin(',
  'self::SUPPORT_TICKET_SUBJECT,',
  "(int) $state['expected_latest_message_id']",
  '$this->clearAdminSupportState($msg);',
  '$lock->release();',
], 'Role-rechecked, stale-safe administrator reply');
assert(!adminInput.includes("->where('id', '>', (int) $state['expected_latest_message_id'])"),
  'Admin reply recovery can misattribute an identical concurrent web reply.');

const supportAdmin = between(plugin, 'protected function supportAdminActor', 'protected function supportTicketForAdmin');
includesAll(supportAdmin, [
  '$user = $this->boundUser($msg, false);',
  'if (!$user || !$user->is_admin)',
  "$this->text('forbidden', $locale)",
], 'Currently linked is_admin support authority');
assert(!supportAdmin.includes('$user->is_staff') && !supportAdmin.includes('$user->is_reseller'),
  'Staff or reseller status can substitute for admin support authority.');

const adminTicket = between(plugin, 'protected function supportTicketForAdmin', 'protected function adminSupportKey');
includesAll(adminTicket, [
  "->where('subject', self::SUPPORT_TICKET_SUBJECT)",
  "->where('status', Ticket::STATUS_OPENING)",
  "->whereHas('user', fn ($query) => $query->where('is_reseller', true))",
], 'Target reseller role revalidation');

const adminState = between(plugin, 'protected function adminSupportKey', 'protected function issueSupportCallback');
includesAll(adminState, [
  "'telegram:support:admin-state:' . $actorId",
  "(int) ($state['admin_user_id'] ?? 0) <= 0",
  "(int) ($state['ticket_id'] ?? 0) <= 0",
  "(int) ($state['expected_latest_message_id'] ?? 0) <= 0",
  "preg_match('/^[a-f0-9]{16}$/', (string) ($state['nonce'] ?? ''))",
  "(int) $state['admin_user_id'] === (int) $admin->id",
  'Cache::forget($key)',
], 'Admin reply state ownership and expiry');

const callbackSecurity = between(plugin, 'protected function issueSupportCallback', 'protected function maskedEmail');
includesAll(callbackSecurity, [
  "in_array($action, ['view', 'reply'], true)",
  "preg_match('/^[1-9][0-9]{0,19}$/', trim((string) $admin->telegram_id))",
  '$token = bin2hex(random_bytes(16));',
  "'admin_user_id' => (int) $admin->id",
  "'telegram_id' => (string) $admin->telegram_id",
  "'action' => $action",
  "'ticket_id' => $ticketId",
  "preg_match('/^[a-f0-9]{32}$/', $token)",
  "(int) ($payload['admin_user_id'] ?? 0) !== (int) $admin->id",
  "hash_equals((string) ($payload['telegram_id'] ?? ''), $this->actorId($msg))",
  "hash_equals((string) ($payload['action'] ?? ''), $action)",
  'Cache::forget($key);',
  "'telegram:support:callback:' . hash('sha256', $token)",
  'int $ttlSeconds = self::SUPPORT_CALLBACK_TTL_SECONDS',
  '$ttlSeconds = max(60, min(self::SUPPORT_NOTIFICATION_CALLBACK_TTL_SECONDS, $ttlSeconds));',
  "'expires_at' => time() + $ttlSeconds",
  '], $ttlSeconds);',
], 'Opaque one-time callback bound to admin user, Telegram actor and action');

includesAll(plugin, [
  'private const SUPPORT_CALLBACK_TTL_SECONDS = 900;',
  'private const SUPPORT_NOTIFICATION_CALLBACK_TTL_SECONDS = 86400;',
], 'Separate interactive and queued-notification callback lifetimes');

includesAll(resellerSupportJob, [
  'class SendTelegramResellerSupportNotificationJob implements ShouldQueue',
  'protected int $adminUserId,',
  'protected int $ticketId,',
  'protected int $ticketMessageId,',
  'if (!TelegramService::runtimeEnabled())',
  "$plugin = $pluginManager->getPlugin('telegram');",
  "method_exists($plugin, 'deliverQueuedSupportNotification')",
  '$this->adminUserId,',
  '$this->ticketId,',
  '$this->ticketMessageId,',
], 'Identifier-only support job and worker-time plugin enablement');
const integrationGate = resellerSupportJob.indexOf('if (!TelegramService::runtimeEnabled())');
const loadDeliveryPlugin = resellerSupportJob.indexOf("$plugin = $pluginManager->getPlugin('telegram');");
const invokeDelivery = resellerSupportJob.indexOf('$plugin->deliverQueuedSupportNotification(');
assert(integrationGate >= 0 && integrationGate < loadDeliveryPlugin && loadDeliveryPlugin < invokeDelivery,
  'Queued support worker can load or invoke Telegram after an incomplete integration gate.');
const centralRuntimeGate = between(telegramService, 'public static function runtimeEnabled', 'public function sendMessage');
includesAll(centralRuntimeGate, [
  "admin_setting('telegram_bot_token', '')",
  "admin_setting('telegram_bot_enable', false)",
  'FILTER_VALIDATE_BOOLEAN',
  "->where('code', 'telegram')",
  "->where('is_enabled', true)",
], 'Central current-config Telegram runtime gate');
for (const forbiddenJobField of ['$telegramId', '$chatId', '$text', '$parseMode', '$replyMarkup']) {
  assert(!resellerSupportJob.includes(forbiddenJobField),
    `Queued support job serializes stale delivery field: ${forbiddenJobField}`);
}

const lockedReply = between(ticketService, 'public function replyByAdmin', 'public function createTicket');
includesAll(lockedReply, [
  '?int $expectedLatestMessageId = null',
  'DB::transaction(function () use (',
  '$expectedLatestMessageId,',
  '$ticket = $query->lockForUpdate()->first();',
  'if ($expectedLatestMessageId !== null)',
  "->where('ticket_id', $ticket->id)",
  "->max('id')",
  'if ($latestMessageId !== $expectedLatestMessageId)',
  'TicketMessage::query()->create([',
  "Log::error('Ticket administrator reply post-save hook failed'",
  "Log::error('Ticket administrator reply email notification failed'",
  'return $ticketMessage;',
], 'Locked expected-latest TicketService reply');
const locked = lockedReply.indexOf('$ticket = $query->lockForUpdate()->first();');
const latest = lockedReply.indexOf('if ($expectedLatestMessageId !== null)');
const insert = lockedReply.indexOf('TicketMessage::query()->create([');
assert(locked >= 0 && locked < latest && latest < insert,
  'Expected-latest validation is not enforced after the ticket lock and before insert.');
assert(lockedReply.includes("try {\n            HookManager::call('ticket.reply.admin.after'")
    && lockedReply.includes('try {\n            $this->sendEmailNotify($ticket, $ticketMessage);'),
  'A post-commit notification failure can make callers retry a durable admin reply.');

const resellerAuthority = between(resellerService, 'public function canManage', 'public function availablePlans');
assert(resellerAuthority.includes('return (bool) $actor->is_reseller;')
  && !resellerAuthority.includes('$actor->is_admin')
  && !resellerAuthority.includes('$actor->is_staff'),
  'Support target authority is not isolated to is_reseller.');

for (const callback of [
  'support:inbox:' + '9'.repeat(19),
  `support:view:${'a'.repeat(32)}`,
  `support:reply:${'a'.repeat(32)}`,
  'support:cancel',
]) {
  assert(Buffer.byteLength(callback, 'utf8') <= 64,
    `Support callback exceeds Telegram's 64-byte limit: ${callback}`);
}

const localeNames = ['en', 'fa', 'ja', 'ko', 'ru', 'vi', 'zh-CN', 'zh-TW'];
const supportKeys = [
  'support_intro', 'support_resumed', 'support_unavailable', 'support_message_invalid',
  'support_rate_limited', 'support_failed', 'support_sent', 'support_closed',
  'support_cancelled', 'support_admin_reply', 'support_admin_delivered', 'support_admin_saved',
  'support_inbox_title', 'support_inbox_empty', 'support_admin_notification',
  'support_admin_detail', 'support_history_reseller', 'support_history_admin',
  'support_admin_reply_prompt', 'support_inbox_stale', 'support_role_reseller',
  'button_support', 'button_close_support', 'button_cancel_support',
  'button_support_inbox', 'button_view_support', 'button_reply_support',
  'button_previous', 'button_next', 'button_refresh',
];
const catalogs = Object.fromEntries(localeNames.map((locale) => [
  locale,
  JSON.parse(read(`plugins-core/Telegram/locales/${locale}.json`)).messages,
]));
const referenceKeys = Object.keys(catalogs.en).sort();
const placeholders = (value) => [...String(value).matchAll(/:[A-Za-z_][A-Za-z0-9_]*/g)]
  .map((match) => match[0]).sort();
for (const locale of localeNames) {
  assert.deepStrictEqual(Object.keys(catalogs[locale]).sort(), referenceKeys,
    `${locale} message catalog differs from English.`);
  for (const key of supportKeys) {
    assert(typeof catalogs[locale][key] === 'string' && catalogs[locale][key].trim(),
      `${locale} is missing ${key}.`);
    assert.deepStrictEqual(placeholders(catalogs[locale][key]), placeholders(catalogs.en[key]),
      `${locale}.${key} changed support interpolation placeholders.`);
    if (locale !== 'en') {
      assert.notStrictEqual(catalogs[locale][key], catalogs.en[key],
        `${locale}.${key} silently falls back to English.`);
    }
  }
}

assert(!plugin.includes('supportChatId') && !plugin.includes('ZG-SUPPORT-REF:'),
  'Legacy configured-chat/reference parsing remains in the one-bot inbox implementation.');
assert(!plugin.includes('->getMessage()'), 'Support logs may contain raw exception details or secrets.');

console.log('Telegram reseller support v2.3 one-bot inbox, admin isolation, callback/state security, stale-write protection and 8-locale contracts verified.');
