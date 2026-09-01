const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const css = fs.readFileSync('luck-overrides.css', 'utf8');

assert.strictEqual(
  (template.match(/id="luck-telegram-card"/g) || []).length,
  1,
  'the customer dashboard must expose exactly one Telegram card'
);
assert.match(
  template,
  /<div id="luck-telegram-card-parking"[^>]*\bhidden\b[^>]*>[\s\S]*?<section id="luck-telegram-card"[^>]*\bhidden\b/,
  'the card must start hidden in its dedicated non-Vue parking host'
);

const endpointAnchor = template.indexOf("var ENDPOINT = '/api/v1/user/telegram/getBotInfo'");
const scriptStart = template.lastIndexOf('<script>', endpointAnchor) + '<script>'.length;
const scriptEnd = template.indexOf('</script>', endpointAnchor);
assert(endpointAnchor >= 0 && scriptStart >= '<script>'.length && scriptEnd > scriptStart, 'Telegram enhancement script is missing');
const telegramScript = template.slice(scriptStart, scriptEnd);
assert.doesNotThrow(
  () => new vm.Script(telegramScript),
  'Telegram dashboard enhancement must remain valid browser JavaScript'
);

assert(
  telegramScript.includes("var ENDPOINT = '/api/v1/user/telegram/getBotInfo'") &&
    telegramScript.includes("'Authorization': token") &&
    telegramScript.includes("cache: 'no-store'"),
  'Telegram status must use the authenticated user endpoint without stale HTTP caching'
);
assert(
  telegramScript.includes('var isDashboardRoute = function () {') &&
    telegramScript.includes('var cardIsMounted = function () {') &&
    telegramScript.includes("header.classList.contains('luck-subscription-header')") &&
    telegramScript.includes("header.parentElement.classList.contains('subscription-cards-section')") &&
    telegramScript.includes('if (!token || !isDashboardRoute() || card.hidden || !cardIsMounted())') &&
    telegramScript.includes("if (!isDashboardRoute() || !normalizeToken())") &&
    telegramScript.includes('response.status === 401 || response.status === 403'),
  'requests and visible state must be limited to an authenticated dashboard card'
);

assert(
  telegramScript.includes("var subscriptionSection = firstVisible('.subscription-cards-section')") &&
    telegramScript.includes("classList.contains('section-title')") &&
    telegramScript.includes("classList.contains('subscription-grid')") &&
    telegramScript.includes("header.className = 'luck-subscription-header'") &&
    telegramScript.includes('subscriptionSection.insertBefore(header, subscriptionGrid)') &&
    telegramScript.includes('header.insertBefore(sectionTitle, header.firstChild)') &&
    telegramScript.includes('header.insertBefore(card, sectionTitle.nextElementSibling)') &&
    telegramScript.includes('header.nextElementSibling === subscriptionGrid'),
  'the real title and compact Telegram control must share one header directly before the untouched links grid'
);
assert(!telegramScript.includes('.right-column'), 'the Telegram card must never fall back to the dashboard right column');
assert(!telegramScript.includes('document.body.appendChild(card)'), 'the Telegram card must never become an orphaned body child');
assert(
  telegramScript.includes('if (card.parentElement !== parking) moveCard(parking, null)') &&
    telegramScript.includes('if (!subscriptionSection || !sectionTitle || !subscriptionGrid)') &&
    telegramScript.includes("card.classList.remove('luck-telegram-card--subscription')"),
  'missing, logged-out, and off-route states must return the hidden card to its dedicated parking host'
);
assert.match(
  telegramScript,
  /var moveCard = function \(parent, beforeNode\) \{[\s\S]*?placementObserver\.disconnect\(\)[\s\S]*?parent\.insertBefore\(card, beforeNode \|\| null\)[\s\S]*?observeApp\(\)/,
  'card moves must pause and re-arm the app observer so placement cannot trigger a MutationObserver loop'
);
assert.strictEqual(
  (telegramScript.match(/parent\.insertBefore\(card, beforeNode \|\| null\)/g) || []).length,
  1,
  'all card moves must use the single observer-safe placement helper'
);

const safeUrlMatch = telegramScript.match(/var safeTelegramUrl = (function \(value\) \{[\s\S]*?\n      \});/);
assert(safeUrlMatch, 'safe Telegram URL validator is missing');
const safeTelegramUrl = vm.runInNewContext(`(${safeUrlMatch[1]})`, { URL });
assert.strictEqual(
  safeTelegramUrl('https://t.me/ZaoGuang_bot?start=bind_once'),
  'https://t.me/ZaoGuang_bot?start=bind_once',
  'a server-issued HTTPS t.me deep link must remain usable'
);
assert.strictEqual(
  safeTelegramUrl('https://t.me/ZaoGuang_bot?start=menu'),
  'https://t.me/ZaoGuang_bot?start=menu',
  'a linked user must be able to reopen the inline-button menu through a safe deep link'
);
for (const unsafeUrl of [
  'http://t.me/ZaoGuang_bot?start=bind_once',
  'https://telegram.me/ZaoGuang_bot?start=bind_once',
  'https://t.me.evil.example/ZaoGuang_bot',
  'https://user@t.me/ZaoGuang_bot',
  'https://t.me:444/ZaoGuang_bot',
  'javascript:alert(1)'
]) {
  assert.strictEqual(safeTelegramUrl(unsafeUrl), '', `unsafe Telegram URL was accepted: ${unsafeUrl}`);
}
assert(
  telegramScript.includes('var bindUrl = safeTelegramUrl(data.bind_url)') &&
    telegramScript.includes('primary.href = bindUrl'),
  'the primary action must use only the validated short-lived URL returned by the API'
);
assert(!telegramScript.includes("primary.addEventListener('click'"), 'opening Telegram must not be intercepted or revoke the clicked one-time link');
assert(!/bind_url\s*[+`]/.test(telegramScript), 'the dashboard must not construct a Telegram payload from local account data');
assert(!telegramScript.includes('subscription_url'), 'the Telegram card must not expose or copy a subscription URL');
assert(!telegramScript.includes('console.log'), 'Telegram responses must never be logged in the browser');

assert(
  telegramScript.includes("setState(linked ? 'linked' : 'unlinked'") &&
    telegramScript.includes("showUnavailable('unavailable'") &&
    telegramScript.includes("showUnavailable('error', currentCopy.error)") &&
    telegramScript.includes("refresh.addEventListener('click', function () { loadBotInfo(true); })") &&
    telegramScript.includes("resellerNote.hidden = !(data.capabilities && data.capabilities.reseller === true)") &&
    telegramScript.includes('linked ? currentCopy.open : currentCopy.link'),
  'linked, unlinked, unavailable, error, reseller, and manual refresh states must remain explicit'
);
assert(
  telegramScript.includes('scheduleBindingRefresh(data.binding_expires_in)') &&
    telegramScript.includes('Math.min(Math.max(30, expiresIn - 30) * 1000, 2147483647)') &&
    telegramScript.includes('clearBindingRefresh();') &&
    (telegramScript.match(/scheduleBindingRefresh\(/g) || []).length === 1,
  'each valid deep link must receive one bounded pre-expiry refresh without rearming from error paths'
);

for (const locale of ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  assert(telegramScript.includes(`'${locale}': {`), `Telegram dashboard copy is missing ${locale}`);
}
assert(
  telegramScript.includes("refresh.setAttribute('aria-label', currentCopy.refreshAria)") &&
    template.includes('role="status" aria-live="polite"') &&
    template.includes('aria-busy="true"') &&
    template.includes('target="_blank" rel="noopener noreferrer"'),
  'refresh, async status, and the external action need accessible and safe semantics'
);

assert.match(
  css,
  /\.luck-telegram-card-parking\[hidden\],[\s\S]*?\.luck-telegram-card\[hidden\]\s*\{[\s\S]*?display:\s*none\s*!important/,
  'both parked and route-hidden Telegram UI must remain non-rendered'
);
assert.match(
  css,
  /\.subscription-container\[data-v-3709f5eb\]\s*\{[\s\S]*?padding:\s*32px\s*!important[\s\S]*?\.subscription-cards-section\[data-v-3709f5eb\]\s*\{[\s\S]*?margin-top:\s*0\s*!important/,
  'the subscription box must match the approved Download apps top and bottom padding geometry'
);
assert.match(
  css,
  /\.subscription-cards-section\[data-v-3709f5eb\]\s*>\s*\.luck-subscription-header\s*\{[\s\S]*?display:\s*flex[\s\S]*?min-height:\s*40px[\s\S]*?margin-bottom:\s*16px/,
  'the title row must reproduce the Download apps 40px control plus 16px title-to-grid gap'
);
assert.match(
  css,
  /\.luck-subscription-header\s*>\s*\.luck-telegram-card--subscription\s*\{[\s\S]*?display:\s*flex[\s\S]*?flex:\s*0\s+1\s+540px[\s\S]*?min-height:\s*40px[\s\S]*?border-radius:\s*999px/,
  'desktop must render the selected compact Option 1 pill on the heading row'
);
assert.match(
  css,
  /\.luck-subscription-header \.luck-telegram-status\s*\{[\s\S]*?text-overflow:\s*ellipsis[\s\S]*?word-break:\s*keep-all[\s\S]*?white-space:\s*nowrap/,
  'translated status copy must remain horizontal without vertical character stacking'
);
assert.match(
  css,
  /\.luck-subscription-header \.luck-telegram-primary\s*\{[\s\S]*?overflow-wrap:\s*normal[\s\S]*?word-break:\s*keep-all[\s\S]*?white-space:\s*nowrap/,
  'translated Telegram actions must stay on a horizontal button'
);
assert.match(
  css,
  /@container luck-subscription-section \(max-width:\s*560px\)[\s\S]*?grid-template-columns:\s*30px\s+minmax\(0,\s*1fr\)\s+30px[\s\S]*?\.luck-telegram-actions[\s\S]*?grid-row:\s*3/,
  'narrow phones must use a bounded three-column control without overlap'
);
assert.match(
  css,
  /@container luck-subscription-section \(max-width:\s*720px\)[\s\S]*?\.luck-subscription-header[\s\S]*?flex-direction:\s*column[\s\S]*?\.luck-telegram-card--subscription[\s\S]*?width:\s*100%/,
  'narrow subscription containers must wrap the compact control below the title at full width'
);
assert.match(
  css,
  /html\[dir="rtl"\] \.luck-subscription-header,[\s\S]*?direction:\s*rtl[\s\S]*?text-align:\s*start/,
  'Persian layout must use logical RTL alignment'
);
assert.match(css, /\.luck-telegram-primary:focus-visible/, 'keyboard users need a visible Telegram action focus ring');

class FakeNode {
  constructor(id, classes = []) {
    this.id = id;
    this.dataset = {};
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.children = [];
    this.parentElement = null;
    this.attributes = {};
    this.listeners = {};
    this.insertions = 0;
    const names = new Set(classes);
    let className = classes.join(' ');
    this.classList = {
      contains: (name) => names.has(name),
      add: (name) => names.add(name),
      remove: (name) => names.delete(name)
    };
    Object.defineProperty(this, 'className', {
      get: () => className,
      set: (value) => {
        className = String(value || '');
        names.clear();
        className.split(/\s+/).filter(Boolean).forEach((name) => names.add(name));
      }
    });
  }

  get firstChild() {
    return this.children[0] || null;
  }

  get nextElementSibling() {
    if (!this.parentElement) return null;
    const index = this.parentElement.children.indexOf(this);
    return this.parentElement.children[index + 1] || null;
  }

  get previousElementSibling() {
    if (!this.parentElement) return null;
    const index = this.parentElement.children.indexOf(this);
    return index > 0 ? this.parentElement.children[index - 1] : null;
  }

  insertBefore(child, beforeNode) {
    if (child.parentElement) {
      const previousIndex = child.parentElement.children.indexOf(child);
      if (previousIndex >= 0) child.parentElement.children.splice(previousIndex, 1);
    }
    const index = beforeNode === null ? this.children.length : this.children.indexOf(beforeNode);
    assert(index >= 0, 'fixture insertion point must belong to its parent');
    this.children.splice(index, 0, child);
    child.parentElement = this;
    this.insertions += 1;
  }

  querySelector(selector) {
    return selector === 'span' ? this.label : null;
  }

  getClientRects() {
    return this.visible === false ? [] : [{}];
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  removeAttribute(name) {
    delete this.attributes[name];
    if (name === 'href') this.href = '';
  }

  addEventListener(name, listener) {
    (this.listeners[name] ||= []).push(listener);
  }
}

async function verifyRuntimePlacement() {
  const card = new FakeNode('luck-telegram-card', ['luck-telegram-card']);
  card.hidden = true;
  const parking = new FakeNode('luck-telegram-card-parking', ['luck-telegram-card-parking']);
  parking.hidden = true;
  parking.insertBefore(card, null);

  const app = new FakeNode('app');
  const section = new FakeNode('subscription-section', ['subscription-cards-section']);
  const title = new FakeNode('subscription-title', ['section-title']);
  const linksGrid = new FakeNode('subscription-links-grid', ['subscription-grid']);
  section.insertBefore(title, null);
  section.insertBefore(linksGrid, null);
  app.insertBefore(section, null);
  section.insertions = 0;

  const status = new FakeNode('luck-telegram-status');
  const description = new FakeNode('luck-telegram-description');
  const resellerNote = new FakeNode('luck-telegram-reseller-note');
  const primary = new FakeNode('luck-telegram-primary');
  primary.label = new FakeNode('primary-label');
  const unavailable = new FakeNode('luck-telegram-unavailable');
  const footnote = new FakeNode('luck-telegram-footnote');
  const refresh = new FakeNode('luck-telegram-refresh');
  refresh.label = new FakeNode('refresh-label');

  const byId = new Map([
    [card.id, card], [parking.id, parking], [app.id, app], [status.id, status],
    [description.id, description], [resellerNote.id, resellerNote], [primary.id, primary],
    [unavailable.id, unavailable], [footnote.id, footnote], [refresh.id, refresh]
  ]);
  const document = {
    documentElement: { lang: 'en-US' },
    getElementById: (id) => byId.get(id) || null,
    querySelectorAll: (selector) => selector === '.subscription-cards-section' ? [section] : [],
    createElement: () => new FakeNode('generated-header')
  };

  let nextTimerId = 1;
  const timers = [];
  const setTimeout = (listener, delay = 0) => {
    const timer = { id: nextTimerId++, listener, delay: Number(delay) || 0, cancelled: false };
    timers.push(timer);
    return timer.id;
  };
  const clearTimeout = (id) => {
    const timer = timers.find((item) => item.id === id);
    if (timer) timer.cancelled = true;
  };
  const runTimersThrough = (maximumDelay) => {
    for (;;) {
      const index = timers.findIndex((timer) => !timer.cancelled && timer.delay <= maximumDelay);
      if (index < 0) return;
      const [timer] = timers.splice(index, 1);
      timer.listener();
    }
  };

  const windowListeners = {};
  let observer = null;
  class FakeMutationObserver {
    constructor(listener) {
      this.listener = listener;
      this.disconnectCount = 0;
      observer = this;
    }
    observe() { this.observing = true; }
    disconnect() { this.observing = false; this.disconnectCount += 1; }
  }

  let fetchCount = 0;
  let lastRequest = null;
  const fetch = (url, options) => {
    fetchCount += 1;
    lastRequest = { url, options };
    const data = fetchCount === 1
      ? {
          enabled: true,
          linked: false,
          bind_url: 'https://t.me/ZaoGuang_bot?start=bind_runtime',
          binding_expires_in: 600,
          capabilities: { reseller: true }
        }
      : {
          enabled: true,
          linked: true,
          bind_url: 'https://t.me/ZaoGuang_bot?start=menu',
          binding_expires_in: null,
          capabilities: { reseller: true }
        };
    return Promise.resolve({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ data })
    });
  };

  const window = {
    V2BOARD_CONFIG: { LANGUAGE: 'en-US' },
    location: { pathname: '/dashboard' },
    MutationObserver: FakeMutationObserver,
    setTimeout,
    clearTimeout,
    addEventListener: (name, listener) => { (windowListeners[name] ||= []).push(listener); }
  };
  const localStorage = { getItem: (key) => key === 'v2board_token' ? 'Bearer runtime-token' : null };
  const context = { document, window, localStorage, fetch, URL, MutationObserver: FakeMutationObserver };
  vm.runInNewContext(telegramScript, context);
  runTimersThrough(0);
  await new Promise((resolve) => setImmediate(resolve));

  const header = section.children[0];
  assert(header.classList.contains('luck-subscription-header'), 'runtime must create the approved shared title row');
  assert.deepStrictEqual(section.children, [header, linksGrid], 'shared header must remain directly before the untouched links grid');
  assert.deepStrictEqual(header.children, [title, card], 'title must remain first and the compact Telegram control second');
  assert.strictEqual(section.insertions, 1, 'initial runtime placement must insert one shared header');
  assert.strictEqual(card.hidden, false, 'authenticated dashboard card must become visible');
  assert.strictEqual(fetchCount, 1, 'initial authenticated placement must request one fresh link');
  assert.strictEqual(lastRequest.url, '/api/v1/user/telegram/getBotInfo');
  assert.strictEqual(lastRequest.options.headers.Authorization, 'Bearer runtime-token');
  assert.strictEqual(card.dataset.state, 'unlinked');
  assert.strictEqual(primary.href, 'https://t.me/ZaoGuang_bot?start=bind_runtime');
  assert.strictEqual(primary.hidden, false);
  assert.strictEqual(resellerNote.hidden, false);

  observer.listener([]);
  runTimersThrough(100);
  assert.strictEqual(section.insertions, 1, 'an observer pass must not reinsert an already-correct shared header');
  assert.strictEqual(fetchCount, 1, 'an idempotent observer pass must not issue another one-time link');
  assert(observer.disconnectCount >= 1, 'runtime insertion must pause the app observer');

  refresh.listeners.click[0]();
  await new Promise((resolve) => setImmediate(resolve));
  assert.strictEqual(fetchCount, 2, 'the explicit refresh button must issue exactly one replacement link');
  assert.strictEqual(card.dataset.state, 'linked');
  assert.strictEqual(primary.href, 'https://t.me/ZaoGuang_bot?start=menu');

  window.location.pathname = '/plan';
  windowListeners.popstate[0]();
  runTimersThrough(100);
  assert.strictEqual(card.parentElement, parking, 'off-route card must return to the non-Vue parking host');
  assert.strictEqual(card.hidden, true, 'off-route card must remain hidden');
  assert.strictEqual(fetchCount, 2, 'leaving the dashboard must not fetch another Telegram link');
}

verifyRuntimePlacement().then(() => {
  console.log('Luck Telegram dashboard placement, lifecycle, URL, i18n and responsive audit passed');
}).catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
