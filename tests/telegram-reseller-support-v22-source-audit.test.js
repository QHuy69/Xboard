const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const plugin = read('plugins-core/Telegram/Plugin.php');
const ticketService = read('app/Services/TicketService.php');
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

assert.strictEqual(config.version, '2.2.0', 'Support-chat schema must ship as Telegram plugin v2.2.0.');
assert.deepStrictEqual(config.config.enable_reseller_support_chat, {
  type: 'boolean',
  default: false,
  label: 'Enable reseller support chat',
  description: config.config.enable_reseller_support_chat.description,
}, 'Reseller support must be an explicit, disabled-by-default feature flag.');
assert.strictEqual(config.config.reseller_support_chat_id.type, 'string');
assert.strictEqual(config.config.reseller_support_chat_id.default, '');
assert(!Object.prototype.hasOwnProperty.call(config.config, 'reseller_telegram_ids'),
  'Support authority must not regress to a Telegram-ID whitelist.');
assert(!Object.prototype.hasOwnProperty.call(config.config, 'reseller_allowed_telegram_ids'),
  'Support authority must not regress to the alternate legacy whitelist key.');
assert(readme.includes('## Chat hỗ trợ cộng tác viên')
  && readme.includes('is_admin')
  && readme.includes('Nhân viên thông thường không có quyền'),
  'Support configuration, persistence, or admin-only authority is undocumented.');

includesAll(plugin, [
  "private const SUPPORT_TICKET_SUBJECT = '[Telegram reseller support]';",
  'private const SUPPORT_MESSAGE_MAX_LENGTH = 1000;',
  'private const SUPPORT_RATE_ATTEMPTS = 6;',
  "'reseller:support:open'",
  "'reseller:support:close:' . $nonce",
  "'reseller:support:cancel:' . $nonce",
  "'text' => $this->text('button_support', $locale)",
  'if ($this->resellerState($msg)) {',
  '$this->handleResellerInput($msg);',
], 'Support entry points and controls');

const menu = between(plugin, 'public function handleResellerCommand', 'protected function startReseller');
assert(menu.includes('if ($this->supportChatId() !== null)'),
  'Disabled or invalid support configuration still changes the reseller menu.');

const userSupport = between(plugin, 'protected function openResellerSupport', 'protected function showResellerPlans');
includesAll(userSupport, [
  'if (!$this->privateChat($msg))',
  '$actor = $this->resellerActor($msg)',
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
  '$this->stateMatches($state, $nonce, \'support_active\')',
  '$this->stateMatches($state, $nonce, \'support_waiting\')',
], 'Private, owned, persistent reseller support flow');
assert(userSupport.indexOf('(new TicketService())->createTicket(') < userSupport.indexOf("HookManager::call($hookName, $ticket)"),
  'Support notification is emitted before the ticket is durable.');
assert(userSupport.includes('support post-save hook failed')
  && userSupport.includes('support acknowledgement failed'),
  'Post-persistence hook or Telegram acknowledgement failures can trigger duplicate ticket messages.');

const relay = between(plugin, 'protected function sendSupportTicketNotify', 'public function handleSupportAdminReply');
includesAll(relay, [
  '$chatId = $this->supportChatId();',
  '(int) $ticket->status !== Ticket::STATUS_OPENING',
  '(int) $message->user_id !== (int) $user->id',
  '!$this->resellerService->canManage($user)',
  '$reference = $this->supportReference($ticket);',
  "'message' => Helper::escapeMarkdown((string) $message->message)",
  'ZG-SUPPORT-REF:',
  "$this->telegramService->sendMessage($chatId, $body, 'markdown')",
], 'Safe support relay');
assert(!relay.includes('$user->telegram_id') && !relay.includes('$user->email'),
  'Relayed support content exposes a Telegram identifier or personal email.');
assert(!relay.includes("'chat_id' =>"), 'Support relay logs its raw Telegram destination.');

const adminReply = between(plugin, 'public function handleSupportAdminReply', 'public function handleTicketReply');
includesAll(adminReply, [
  'hash_equals($chatId, trim((string) ($msg->chat_id ?? \'\')))',
  '$this->deliveryKey($msg) === null',
  'str_starts_with($chatId, \'-\') && $isPrivate',
  '!str_starts_with($chatId, \'-\') && !$isPrivate',
  '$admin = $this->boundUser($msg, false);',
  'if (!$admin || !$admin->is_admin)',
  'RateLimiter::tooManyAttempts($rateKey, self::SUPPORT_RATE_ATTEMPTS)',
  "where('subject', self::SUPPORT_TICKET_SUBJECT)",
  "where('status', Ticket::STATUS_OPENING)",
  '!$this->resellerService->canManage($target)',
  '(new TicketService())->replyByAdmin(',
  'self::SUPPORT_TICKET_SUBJECT,',
  "'message' => Helper::escapeMarkdown($message)",
  "preg_match('/^[1-9][0-9]{0,19}$/', $targetTelegramId)",
], 'Configured-chat, linked-admin support reply');
assert(!adminReply.includes('$admin->is_staff'),
  'Ordinary staff can reply to dedicated reseller support conversations.');
assert(adminReply.includes("->where('id', '>', $latestMessageId)")
  && adminReply.includes("'support_admin_post_save'"),
  'A post-commit hook/email failure can make Telegram persist the same admin reply twice.');

const lockedAdminReply = between(
  ticketService,
  'public function replyByAdmin',
  'public function createTicket'
);
includesAll(lockedAdminReply, [
  'DB::transaction(function () use (',
  "->where('status', Ticket::STATUS_OPENING)",
  "if ($expectedSubject !== null)",
  "->where('subject', $expectedSubject)",
  '$query->lockForUpdate()->first()',
  'TicketMessage::query()->create([',
  '$ticket->reply_status = Ticket::REPLY_STATUS_REPLIED;',
  '$ticket->last_reply_user_id = $userId;',
], 'Locked open-ticket admin reply');
assert(lockedAdminReply.indexOf('$query->lockForUpdate()->first()')
  < lockedAdminReply.indexOf('TicketMessage::query()->create(['),
  'Admin support reply is inserted before the open ticket is locked and re-checked.');

const legacyReply = between(plugin, 'public function handleTicketReply', 'public function handleUnknownCommand');
assert(legacyReply.includes('(string) $ticket->subject === self::SUPPORT_TICKET_SUBJECT')
  && legacyReply.includes("$this->text('support_reference_invalid'"),
  'The legacy numeric ticket reply path can bypass opaque support references.');

const helpers = between(plugin, 'protected function supportChatId', 'protected function backupPassword');
includesAll(helpers, [
  "getConfig('enable_reseller_bot', false)",
  "getConfig('enable_reseller_support_chat', false)",
  "getConfig('reseller_support_chat_id', '')",
  "preg_match('/^-?[1-9][0-9]{0,19}$/', $chatId)",
  "where('user_id', $actor->id)",
  "where('subject', self::SUPPORT_TICKET_SUBJECT)",
  "where('status', Ticket::STATUS_OPENING)",
  "Crypt::encryptString('telegram-support:' . (int) $ticket->id)",
  'Crypt::decryptString($encrypted)',
  "preg_match('/^telegram-support:([1-9][0-9]{0,18})$/', $payload, $matches)",
], 'Restart-safe opaque support routing');
assert(!relay.includes(". (int) $ticket->id ."),
  'The Telegram relay embeds a raw ticket identifier instead of only an opaque reference.');

assert(plugin.includes("Cache::add($deliveryKey, 'processing', self::DELIVERY_TTL_SECONDS)"),
  'Telegram delivery deduplication is absent.');
assert(plugin.includes("'/\\nZG-SUPPORT-REF:\\s*`?([a-f0-9]{80,1024})`?\\s*$/u'"),
  'Admin replies are not tied to the bot message\'s final opaque reference marker.');
assert(!plugin.includes('reseller_telegram_ids'), 'Plugin still reads a hardcoded reseller/support whitelist.');
assert(!plugin.includes('->getMessage()'), 'Support logs may contain raw exception details or secrets.');

for (const callback of [
  'reseller:support:open',
  `reseller:support:close:${'a'.repeat(16)}`,
  `reseller:support:cancel:${'a'.repeat(16)}`,
]) {
  assert(Buffer.byteLength(callback, 'utf8') <= 64,
    `Support callback exceeds Telegram's 64-byte limit: ${callback}`);
}

const localeNames = ['en', 'fa', 'ja', 'ko', 'ru', 'vi', 'zh-CN', 'zh-TW'];
const supportKeys = [
  'support_intro', 'support_resumed', 'support_unavailable', 'support_message_invalid',
  'support_rate_limited', 'support_failed', 'support_sent', 'support_closed',
  'support_cancelled', 'support_admin_relay', 'support_reference_invalid',
  'support_admin_reply', 'support_admin_delivered', 'support_admin_saved',
  'button_support', 'button_close_support', 'button_cancel_support',
];
const catalogs = Object.fromEntries(localeNames.map((locale) => [
  locale,
  JSON.parse(read(`plugins-core/Telegram/locales/${locale}.json`)).messages,
]));
const placeholders = (value) => [...String(value).matchAll(/:[A-Za-z_][A-Za-z0-9_]*/g)]
  .map((match) => match[0]).sort();
for (const locale of localeNames) {
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

console.log('Telegram reseller support v2.2 persistent ticket relay, admin-only routing, nonce, dedupe, privacy, limits and 8-locale contracts verified.');
