{{-- ZaoGuang VPN custom Luck integration layer. Copyright (c) 2026 ZaoGuang VPN. --}}
@php
  $luckBrandTitle = trim((string) ($title ?? ''));
  if ($luckBrandTitle === '') {
    $luckBrandTitle = 'ZaoGuang Service';
  }
  $luckBrandLogoUrl = trim((string) ($logo ?? ''));
  $luckDonatePlanIds = collect(preg_split('/[,\s]+/', (string) env('LUCK_DONATE_PLAN_IDS', '1'), -1, PREG_SPLIT_NO_EMPTY))
    ->map(fn ($id) => (int) $id)
    ->filter(fn ($id) => $id > 0)
    ->unique()
    ->values()
    ->all();
@endphp
<!doctype html>
<html lang="auto">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#3b82f6">
  <meta name="author" content="ZaoGuang VPN">
  <meta name="copyright" content="Copyright (c) 2026 ZaoGuang VPN">
  <link rel="icon" type="image/svg+xml" href="/theme/{{$theme}}/favicon.svg">
  <title>ZaoGuang Service</title>
  <link rel="modulepreload" crossorigin href="/theme/{{$theme}}/assets/DM1yaN1X-fresh.js">
  <link rel="modulepreload" crossorigin href="/theme/{{$theme}}/assets/BEq_qS6Y-fresh.js">
  <link rel="modulepreload" crossorigin href="/theme/{{$theme}}/assets/0I8bmyai.js">
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/DmSyTPzn.css">
  <!-- Keep the shell and dashboard styles render-blocking. Vite normally
       injects these route styles asynchronously, which can leave the page
       permanently unstyled when a preload request is interrupted. -->
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/BbO9A4Tv.css?v=1">
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/BXdzbR5Q.css?v=1">
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/CrZoyNRZ.css?v=1">
  <link id="luck-overrides-stylesheet" rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/luck-overrides.css?v=29">
  <script>
    /* Never change routes in response to a global module/preload event. Some
       mobile WebKit builds emit those events for optional preloads even after
       the app mounted successfully, which previously caused a false reload
       loop and appended luck_reload to the address bar. */
    (function () {
      try {
        sessionStorage.removeItem('luck_chunk_retry_at');
        sessionStorage.removeItem('luck_boot_retry_at');
        // Older invitation links used hash routing (/#/register?code=...).
        // Luck now uses history routing, so normalize the URL before Vue
        // mounts and preserve the invitation code in the real query string.
        if (/^#\/register(?:\?|$)/.test(window.location.hash)) {
          var legacyRoute = window.location.hash.slice(1);
          window.history.replaceState(window.history.state, '', legacyRoute);
        }
        var url = new URL(window.location.href);
        var changed = url.searchParams.has('luck_reload') || url.searchParams.has('luck_boot');
        url.searchParams.delete('luck_reload');
        url.searchParams.delete('luck_boot');
        if (changed) window.history.replaceState(window.history.state, '', url.toString());
      } catch (ignore) {}
    }());
  </script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/BBbuoBq5-fresh.js?v=65"></script>
</head>
<body>
  <div id="app"></div>
  <div class="luck-shell-actions">
    <a id="luck-app-download" class="luck-app-download" href="{{ env('LUCK_RESOURCES_URL', 'https://resources.zaoguang-vpn.com') }}" rel="noopener noreferrer" aria-label="Tải ứng dụng" title="Tải ứng dụng" hidden>
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v11m0 0 4-4m-4 4-4-4M5 18v2h14v-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span class="luck-app-download-label">Tải ứng dụng</span>
    </a>
    <button id="luck-donate-banner" class="luck-donate-banner" type="button" aria-haspopup="dialog" hidden>
      <span class="luck-donate-banner-label">Ủng hộ</span>
    </button>
  </div>
  <div id="luck-telegram-card-parking" class="luck-telegram-card-parking" hidden aria-hidden="true">
  <section id="luck-telegram-card" class="luck-telegram-card" hidden aria-labelledby="luck-telegram-title" aria-busy="true" data-state="loading">
    <span class="luck-telegram-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" focusable="false"><path d="m20.4 4.2-3 14.1c-.2 1-1 1.3-1.8.8l-4.6-3.4-2.2 2.1c-.2.3-.5.5-.9.5l.3-4.7 8.6-7.8c.4-.3-.1-.5-.6-.2L5.6 12.3 1 10.8c-1-.3-1-1 .2-1.4l18-6.9c.8-.3 1.5.2 1.2 1.7Z" fill="currentColor"/></svg>
    </span>
    <div class="luck-telegram-heading">
      <h2 id="luck-telegram-title">Telegram</h2>
      <span id="luck-telegram-description" class="luck-telegram-description">Nhận thông báo tài khoản</span>
    </div>
    <span id="luck-telegram-status" class="luck-telegram-status" role="status" aria-live="polite">Đang kiểm tra</span>
    <div class="luck-telegram-actions">
      <a id="luck-telegram-primary" class="luck-telegram-primary" href="#" target="_blank" rel="noopener noreferrer" hidden>
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="m20.4 4.2-3 14.1c-.2 1-1 1.3-1.8.8l-4.6-3.4-2.2 2.1c-.2.3-.5.5-.9.5l.3-4.7 8.6-7.8c.4-.3-.1-.5-.6-.2L5.6 12.3 1 10.8c-1-.3-1-1 .2-1.4l18-6.9c.8-.3 1.5.2 1.2 1.7Z" fill="currentColor"/></svg>
        <span>Liên kết bằng Telegram</span>
      </a>
      <span id="luck-telegram-unavailable" class="luck-telegram-unavailable" hidden>Bot Telegram hiện chưa khả dụng.</span>
    </div>
    <button id="luck-telegram-refresh" class="luck-telegram-refresh" type="button" aria-label="Làm mới trạng thái Telegram" title="Làm mới trạng thái Telegram">
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span>Làm mới</span>
    </button>
    <span id="luck-telegram-reseller-note" class="luck-telegram-reseller-note" hidden>Công cụ quản lý khách hàng và gói dịch vụ cũng đã được bật cho tài khoản cộng tác viên của bạn.</span>
    <span id="luck-telegram-footnote" class="luck-telegram-footnote">Không cần nhập lệnh hoặc sao chép liên kết thủ công.</span>
  </section>
  </div>
  <div id="luck-donate-modal" class="luck-donate-modal" hidden role="dialog" aria-modal="true" aria-labelledby="luck-donate-title">
    <div class="luck-donate-modal-card">
      <div class="luck-donate-modal-content">
        <h2 id="luck-donate-title">Bạn đang sử dụng gói chống lag mùa đứt cáp</h2>
        <p id="luck-donate-message" class="luck-donate-message">Ủng hộ mình tại đây để duy trì đường truyền ổn định.</p>
        <img class="luck-donate-qr" src="/luck-donate-qr.svg" alt="Mã QR ủng hộ" decoding="async">
        <dl class="luck-donate-bank" aria-label="{{ __('Donation account information') }}">
          <div><dt id="luck-donate-bank-label">Ngân hàng</dt><dd>ACB</dd></div>
          <div><dt id="luck-donate-account-label">Số tài khoản</dt><dd>35333297</dd></div>
          <div><dt id="luck-donate-owner-label">Chủ tài khoản</dt><dd>NGUYEN HOANG QUANG HUY</dd></div>
        </dl>
        <p id="luck-donate-thanks" class="luck-donate-thanks">Cảm ơn bạn đã đóng góp và đồng hành.</p>
      </div>
      <div class="luck-donate-actions">
        <button id="luck-donate-close" class="luck-donate-close" type="button">OK</button>
      </div>
    </div>
  </div>
  <script>window.LUCK_SERVER_LANGUAGES = @json(request()->getLanguages()); window.LUCK_DEFAULT_LANGUAGE = "vi-VN";</script>
  <script src="/theme/{{$theme}}/clients.js"></script>
  <script src="/theme/{{$theme}}/config.js"></script>
  <script id="luck-runtime-branding">
    (function (root, serverBrand) {
      'use strict';
      var config = root.V2BOARD_CONFIG = root.V2BOARD_CONFIG || {};
      var logoConfig = config.LOGO = config.LOGO || {};
      var title = String(serverBrand.title || '').trim() || 'ZaoGuang Service';
      var adminLogo = String(serverBrand.logo || '').trim();
      var themeLogo = String(logoConfig.IMAGE_URL || '').trim();
      var imageUrl = adminLogo || themeLogo;

      /* Luck normally reads only its static config.js and ignores the logo
         already supplied by Xboard. Bridge that contract before Vue mounts.
         A blank admin/theme logo must still produce a real wordmark, while a
         failed custom image can fall back to the packaged ZaoGuang icon. */
      config.APP_TITLE = String(config.APP_TITLE || '').trim() || title;
      logoConfig.IMAGE_URL = imageUrl;
      logoConfig.FALLBACK_IMAGE_URL = String(logoConfig.FALLBACK_IMAGE_URL || '').trim() || '/images/favicon.svg';
      logoConfig.ALT_TEXT = String(logoConfig.ALT_TEXT || '').trim() || title;
      logoConfig.TEXT_LOGO = String(logoConfig.TEXT_LOGO || '').trim() || title;
      logoConfig.SHOW_TEXT_LOGO = Boolean(logoConfig.SHOW_TEXT_LOGO || !imageUrl);
    }(window, {
      title: @json($luckBrandTitle),
      logo: @json($luckBrandLogoUrl)
    }));
  </script>
  <script src="/theme/{{$theme}}/i18n-v18.js?v=61"></script>
  <script>
    (function () {
      /* Vue can append lazy route styles after the render-blocking links in the
         template. Keep the responsive overrides as the final author sheet so
         a late dashboard chunk cannot restore fixed card widths. */
      var overrideSheet = document.getElementById('luck-overrides-stylesheet');
      if (!overrideSheet || !document.head) return;
      var placeOverridesLast = function () {
        var authorSheets = document.head.querySelectorAll('link[rel="stylesheet"], style');
        if (authorSheets.length && authorSheets[authorSheets.length - 1] !== overrideSheet) {
          document.head.appendChild(overrideSheet);
        }
      };
      placeOverridesLast();
      if (window.MutationObserver) {
        new MutationObserver(placeOverridesLast).observe(document.head, { childList: true });
      }
    }());
  </script>
  <script>
    (function () {
      // The stock login chunk occasionally misses the first SPA navigation
      // while it is mounting. Let Vue handle the click normally, then repair
      // only a missed transition without reloading the document.
      document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest ? event.target.closest('.register-link') : null;
        if (!target) return;
        window.setTimeout(function () {
          if (window.location.pathname === '/register') return;
          window.history.pushState(window.history.state, '', '/register');
          window.dispatchEvent(new PopStateEvent('popstate'));
        }, 0);
      }, true);
    }());
  </script>
  <script>
    (function () {
      var banner = document.getElementById('luck-donate-banner');
      var modal = document.getElementById('luck-donate-modal');
      var close = document.getElementById('luck-donate-close');
      var download = document.getElementById('luck-app-download');
      if (!banner || !modal || !close || banner.dataset.bound === '1') return;
      banner.dataset.bound = '1';
      var TARGET_PLAN_IDS = @json($luckDonatePlanIds);
      var ELIGIBILITY_ENDPOINT = '/api/v1/user/getSubscribe';
      var CLASH_ICON = '/theme/{{$theme}}/assets/luck-clash.svg?v=2';
      var lang = (window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || document.documentElement.lang || 'vi-VN';
      var labels = {
        'vi-VN': 'Ủng hộ', 'en-US': 'Donate', 'zh-CN': '捐赠', 'zh-TW': '捐贈',
        'ja-JP': '寄付', 'ko-KR': '후원', 'fa-IR': 'حمایت', 'ru-RU': 'Поддержать'
      };
      var label = labels[lang] || labels['vi-VN'];
      var downloadLabels = {
        'vi': 'Tải ứng dụng', 'vi-VN': 'Tải ứng dụng', 'en': 'Download app', 'en-US': 'Download app',
        'zh-CN': '下载应用', 'zh-TW': '下載應用', 'ja-JP': 'アプリをダウンロード', 'ko-KR': '앱 다운로드',
        'fa': 'دانلود برنامه', 'fa-IR': 'دانلود برنامه', 'ru': 'Скачать приложение',
        'ru-RU': 'Скачать приложение', 'ar': 'تنزيل التطبيق', 'ar-SA': 'تنزيل التطبيق'
      };
      var platformCopyByLocale = {
        'vi-VN': { group: 'Chọn hệ điều hành', macos: 'Máy Mac', linux: 'Máy tính Linux', android: 'Thiết bị Android' },
        'en-US': { group: 'Choose an operating system', macos: 'Mac computer', linux: 'Linux computer', android: 'Android device' },
        'zh-CN': { group: '选择操作系统', macos: 'Mac 电脑', linux: 'Linux 电脑', android: 'Android 设备' },
        'zh-TW': { group: '選擇作業系統', macos: 'Mac 電腦', linux: 'Linux 電腦', android: 'Android 裝置' },
        'ja-JP': { group: 'OS を選択', macos: 'Mac コンピュータ', linux: 'Linux コンピュータ', android: 'Android デバイス' },
        'ko-KR': { group: '운영 체제 선택', macos: 'Mac 컴퓨터', linux: 'Linux 컴퓨터', android: 'Android 기기' },
        'fa-IR': { group: 'انتخاب سیستم‌عامل', macos: 'رایانه Mac', linux: 'رایانه Linux', android: 'دستگاه Android' },
        'ru-RU': { group: 'Выберите операционную систему', macos: 'Компьютер Mac', linux: 'Компьютер Linux', android: 'Устройство Android' }
      };
      var platformLocaleAliases = { vi: 'vi-VN', en: 'en-US', zh: 'zh-CN', ja: 'ja-JP', ko: 'ko-KR', fa: 'fa-IR', ru: 'ru-RU' };
      var platformLocale = platformCopyByLocale[lang] ? lang :
        (platformLocaleAliases[String(lang).split('-')[0].toLowerCase()] || 'vi-VN');
      var platformCopy = platformCopyByLocale[platformLocale];
      var PLATFORM_ORDER = ['windows', 'macos', 'linux', 'android', 'ios'];
      var copy = {
        'vi-VN': {
          title: 'Bạn đang sử dụng gói chống lag mùa đứt cáp',
          message: 'Ủng hộ mình tại đây để duy trì đường truyền ổn định.',
          thanks: 'Cảm ơn bạn đã đóng góp và đồng hành.',
          qr: 'Mã QR ủng hộ', bank: 'Ngân hàng', account: 'Số tài khoản', owner: 'Chủ tài khoản'
        },
        'en-US': {
          title: 'You are using the cable-outage anti-lag plan',
          message: 'Support me here to help keep the connection stable.',
          thanks: 'Thank you for your contribution and support.',
          qr: 'Donation QR code', bank: 'Bank', account: 'Account number', owner: 'Account holder'
        },
        'zh-CN': {
          title: '您正在使用断缆抗延迟套餐',
          message: '欢迎在此支持我们，帮助维持稳定连接。',
          thanks: '感谢您的支持与捐助。',
          qr: '捐赠二维码', bank: '银行', account: '账号', owner: '账户名'
        },
        'zh-TW': {
          title: '您正在使用斷纜抗延遲方案',
          message: '歡迎在此支持我們，幫助維持穩定連線。',
          thanks: '感謝您的支持與捐助。',
          qr: '贊助 QR Code', bank: '銀行', account: '帳號', owner: '帳戶名稱'
        },
        'ja-JP': {
          title: '海底ケーブル障害対策プランをご利用中です',
          message: '安定した接続を維持するため、こちらからご支援いただけます。',
          thanks: 'ご支援ありがとうございます。',
          qr: '支援用 QR コード', bank: '銀行', account: '口座番号', owner: '口座名義'
        },
        'ko-KR': {
          title: '해저 케이블 장애 대비 저지연 플랜을 이용 중입니다',
          message: '안정적인 연결 유지를 위해 여기에서 후원하실 수 있습니다.',
          thanks: '후원해 주셔서 감사합니다.',
          qr: '후원 QR 코드', bank: '은행', account: '계좌 번호', owner: '예금주'
        },
        'fa-IR': {
          title: 'شما از طرح کاهش تأخیر در زمان قطعی کابل استفاده می‌کنید',
          message: 'برای کمک به پایدار ماندن اتصال، از اینجا حمایت کنید.',
          thanks: 'از حمایت و همراهی شما سپاسگزاریم.',
          qr: 'کد QR حمایت', bank: 'بانک', account: 'شماره حساب', owner: 'نام صاحب حساب'
        },
        'ru-RU': {
          title: 'Вы используете тариф для снижения задержки при обрыве кабеля',
          message: 'Поддержите нас здесь, чтобы соединение оставалось стабильным.',
          thanks: 'Спасибо за вашу поддержку.',
          qr: 'QR-код для поддержки', bank: 'Банк', account: 'Номер счёта', owner: 'Получатель'
        }
      };
      copy = copy[lang] || copy['vi-VN'];
      var labelNode = banner.querySelector('.luck-donate-banner-label');
      if (labelNode) labelNode.textContent = label;
      banner.setAttribute('aria-label', label);
      var downloadLabel = download && download.querySelector('.luck-app-download-label');
      var localizedDownloadLabel = downloadLabels[lang] || downloadLabels[String(lang).split('-')[0]] || downloadLabels['vi-VN'];
      var resourcesBaseHref = download ? download.getAttribute('href') : '';
      var selectedDownloadPlatform = '';
      if (downloadLabel) downloadLabel.textContent = localizedDownloadLabel;
      if (download) {
        download.setAttribute('aria-label', localizedDownloadLabel);
        download.setAttribute('title', localizedDownloadLabel);
      }
      var title = document.getElementById('luck-donate-title');
      var message = document.getElementById('luck-donate-message');
      var thanks = document.getElementById('luck-donate-thanks');
      var qr = modal.querySelector('.luck-donate-qr');
      var bankLabel = document.getElementById('luck-donate-bank-label');
      var accountLabel = document.getElementById('luck-donate-account-label');
      var ownerLabel = document.getElementById('luck-donate-owner-label');
      if (title) title.textContent = copy.title;
      if (message) message.textContent = copy.message;
      if (thanks) thanks.textContent = copy.thanks;
      if (qr) qr.alt = copy.qr;
      if (bankLabel) bankLabel.textContent = copy.bank;
      if (accountLabel) accountLabel.textContent = copy.account;
      if (ownerLabel) ownerLabel.textContent = copy.owner;
      var open = function () {
        if (banner.hidden) return;
        modal.hidden = false;
        document.body.classList.add('luck-donate-open');
        close.focus();
      };
      var dismiss = function () {
        modal.hidden = true;
        document.body.classList.remove('luck-donate-open');
        if (!banner.hidden) banner.focus();
      };
      var hideDonation = function () {
        banner.hidden = true;
        modal.hidden = true;
        document.body.classList.remove('luck-donate-open');
      };
      var normalizeToken = function () {
        try {
          var token = localStorage.getItem('v2board_token') || '';
          if (!token) return '';
          if (token.charAt(0) === '"') token = JSON.parse(token);
          return typeof token === 'string' ? token.trim() : '';
        } catch (ignore) {
          return '';
        }
      };
      var lastEligibilityKey = '';
      var eligibilityRequest = 0;
      var autoOpened = false;
      var checkEligibility = function (force) {
        var path = window.location.pathname.replace(/\/+$/, '') || '/';
        var token = normalizeToken();
        if (path === '/login' || path === '/register' || !token) {
          eligibilityRequest += 1;
          hideDonation();
          lastEligibilityKey = '';
          autoOpened = false;
          return;
        }
        var eligibilityKey = path + '|' + token;
        if (!force && eligibilityKey === lastEligibilityKey) return;
        lastEligibilityKey = eligibilityKey;
        var requestId = ++eligibilityRequest;
        fetch(ELIGIBILITY_ENDPOINT, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Accept': 'application/json', 'Authorization': token }
        }).then(function (response) {
          if (!response.ok) throw new Error('Subscription check failed');
          return response.json();
        }).then(function (payload) {
          if (requestId !== eligibilityRequest) return;
          var subscription = payload && payload.data;
          try {
            var accountLocale = subscription && subscription.locale;
            var manualLocale = localStorage.getItem('luck_locale_manual') === '1';
            var currentLocale = window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE;
            if (!manualLocale && accountLocale && accountLocale !== currentLocale
                && sessionStorage.getItem('luck_account_locale_applied') !== accountLocale) {
              sessionStorage.setItem('luck_account_locale_applied', accountLocale);
              document.cookie = 'luck_locale=' + encodeURIComponent(accountLocale) + '; path=/; max-age=31536000; SameSite=Lax';
              localStorage.setItem('luck_locale', accountLocale);
              window.location.reload();
              return;
            }
          } catch (ignoreAccountLocale) {}
          var expiresAt = subscription && Number(subscription.expired_at || 0);
          var active = !expiresAt || expiresAt > Math.floor(Date.now() / 1000);
          var eligible = subscription && TARGET_PLAN_IDS.indexOf(Number(subscription.plan_id)) !== -1 && active;
          if (!eligible) {
            hideDonation();
            return;
          }
          banner.hidden = false;
          if (!autoOpened) {
            autoOpened = true;
            open();
          }
        }).catch(function () {
          if (requestId === eligibilityRequest) hideDonation();
        });
      };
      var ICON_IMAGE_SELECTOR = [
        '.app-icon-wrapper img.app-icon',
        '.subscription-icon img.subscription-logo',
        '.payment-method-icon img',
        '.method-icon img',
        'img.import-icon',
        '.avatar-container img',
        '.user-avatar img',
        '.user-avatar-large img'
      ].join(',');
      var iconFallbackLabel = function (image, host) {
        if (host.matches('.subscription-icon.clash')) return 'C';
        if (host.matches('.subscription-icon.v2ray')) return 'V2';
        if (host.matches('.subscription-icon.shadowsocks')) return 'SS';
        if (host.matches('.subscription-icon.singbox')) return 'SB';
        if (host.matches('.subscription-icon.hiddify')) return 'H';
        if (host.matches('.payment-method-icon, .method-icon')) return 'PAY';
        if (host.matches('.avatar-container, .user-avatar, .user-avatar-large')) return 'U';
        var alt = String(image.getAttribute('alt') || '').trim();
        var ascii = alt.replace(/[^A-Za-z0-9]+/g, '').slice(0, 2).toUpperCase();
        return ascii || 'APP';
      };
      var iconImageHost = function (image) {
        return image.closest('.app-icon-wrapper, .subscription-icon, .payment-method-icon, .method-icon, .avatar-container, .user-avatar, .user-avatar-large')
          || image.parentElement;
      };
      var markIconFailed = function (image) {
        var host = iconImageHost(image);
        if (!host) return;
        host.setAttribute('data-luck-icon-fallback', iconFallbackLabel(image, host));
        image.setAttribute('aria-hidden', 'true');
      };
      var markIconLoaded = function (image) {
        var host = iconImageHost(image);
        if (!host) return;
        host.removeAttribute('data-luck-icon-fallback');
        image.removeAttribute('aria-hidden');
      };
      var bindIconImage = function (image) {
        if (image.dataset.luckIconBound !== '1') {
          image.dataset.luckIconBound = '1';
          image.addEventListener('error', function () { markIconFailed(image); });
          image.addEventListener('load', function () {
            if (image.naturalWidth > 0 && image.naturalHeight > 0) markIconLoaded(image);
          });
        }
        if (image.complete) {
          if (image.naturalWidth > 0 && image.naturalHeight > 0) markIconLoaded(image);
          else markIconFailed(image);
        }
      };
      var syncIconVisibility = function () {
        document.querySelectorAll(ICON_IMAGE_SELECTOR).forEach(bindIconImage);
      };
      /* Windows often renders regional-indicator flag emoji as the literal
         ISO letters (for example "CN"). Replace a leading plan-name flag
         with our local SVG sprite so the plan card keeps a real flag icon on
         desktop, while preserving the plan name itself. */
      var PLAN_NAME_FLAG_SELECTOR = '.plans-grid .plan-name, .plan-comparison .plan-name';
      var syncPlanNameFlags = function () {
        document.querySelectorAll(PLAN_NAME_FLAG_SELECTOR).forEach(function (node) {
          var characters = Array.from(String(node.textContent || '').trim());
          var first = characters[0] ? characters[0].codePointAt(0) : 0;
          var second = characters[1] ? characters[1].codePointAt(0) : 0;
          var isRegionalPair = first >= 0x1F1E6 && first <= 0x1F1FF
            && second >= 0x1F1E6 && second <= 0x1F1FF;
          if (!isRegionalPair) {
            if (node.dataset.luckPlanFlag) {
              node.textContent = String(node.textContent || '').trim();
              delete node.dataset.luckPlanFlag;
            }
            return;
          }
          var flagCode = String.fromCharCode(first - 0x1F1E6 + 97, second - 0x1F1E6 + 97);
          var label = characters.slice(2).join('').trim();
          if (!label) return;
          if (node.dataset.luckPlanFlag === flagCode
              && node.querySelector(':scope > .luck-plan-name-flag')) {
            var labelNode = node.querySelector(':scope > .luck-plan-name-label');
            if (labelNode) labelNode.textContent = label;
            return;
          }
          node.textContent = '';
          var flag = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
          flag.classList.add('luck-plan-name-flag');
          flag.setAttribute('viewBox', '0 0 32 22');
          flag.setAttribute('aria-hidden', 'true');
          flag.setAttribute('focusable', 'false');
          flag.style.cssText = 'display:inline-block;width:1.25em;height:.86em;flex:0 0 auto;vertical-align:-.08em;margin-inline-end:.35em;overflow:visible;';
          var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
          var flagHref = '/theme/Luck/assets/luck-flags.svg?v=1#' + flagCode;
          use.setAttribute('href', flagHref);
          use.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', flagHref);
          flag.appendChild(use);
          var labelNode = document.createElement('span');
          labelNode.className = 'luck-plan-name-label';
          labelNode.textContent = label;
          node.append(flag, labelNode);
          node.dataset.luckPlanFlag = flagCode;
        });
      };
      var syncClashIcons = function () {
        document.querySelectorAll('.subscription-icon.clash img.subscription-logo').forEach(function (image) {
          if (image.getAttribute('src') !== CLASH_ICON) image.setAttribute('src', CLASH_ICON);
          image.setAttribute('alt', 'Clash');
        });
        document.querySelectorAll('.subscription-dialog').forEach(function (dialog) {
          var titleNode = dialog.querySelector('.dialog-title');
          var isClash = titleNode && /clash/i.test(titleNode.textContent || '');
          if (!isClash) {
            dialog.removeAttribute('data-luck-subscription');
            var previous = dialog.querySelector('.luck-clash-dialog-logo');
            if (previous) previous.remove();
            return;
          }
          dialog.setAttribute('data-luck-subscription', 'clash');
          var iconHost = dialog.querySelector('.dialog-icon');
          if (iconHost && !iconHost.querySelector('.luck-clash-dialog-logo')) {
            var icon = document.createElement('img');
            icon.className = 'luck-clash-dialog-logo';
            icon.src = CLASH_ICON;
            icon.alt = 'Clash';
            iconHost.appendChild(icon);
          }
        });
      };
      var platformName = function (platform) {
        if (platform === 'macos') return 'macOS';
        if (platform === 'ios') return 'iOS';
        return platform.charAt(0).toUpperCase() + platform.slice(1);
      };
      var platformDescription = function (platform) {
        if (platform === 'macos') return platformCopy.macos;
        if (platform === 'linux') return platformCopy.linux;
        if (platform === 'android') return platformCopy.android;
        return '';
      };
      var scopePlatformTree = function (root) {
        root.setAttribute('data-v-3709f5eb', '');
        root.querySelectorAll('*').forEach(function (node) {
          node.setAttribute('data-v-3709f5eb', '');
        });
      };
      var createPlatformCard = function (platform) {
        var card = document.createElement('div');
        var icon = platform === 'macos'
          ? '<svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.7 12.7c0-2.6 2.1-3.8 2.2-3.9a4.7 4.7 0 0 0-3.7-2c-1.6-.2-3 .9-3.8.9-.8 0-2-1-3.3-1-1.7 0-3.3 1-4.2 2.5-1.8 3.1-.5 7.7 1.3 10.2.9 1.2 1.9 2.6 3.2 2.5 1.3-.1 1.8-.8 3.4-.8 1.5 0 2 .8 3.4.8 1.4 0 2.3-1.3 3.1-2.5a11 11 0 0 0 1.4-2.9 4.4 4.4 0 0 1-3-3.8ZM14.2 5.1A4.5 4.5 0 0 0 15.3 2a4.6 4.6 0 0 0-3 1.5 4.2 4.2 0 0 0-1.1 3c1.1.1 2.2-.5 3-1.4Z"/></svg>'
          : '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3" stroke="currentColor" stroke-width="1.8"/><path d="m7 9 3 3-3 3m6 0h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        card.className = 'platform-card ' + platform + ' luck-added-platform';
        card.innerHTML = '<div class="platform-icon">' + icon + '</div>' +
          '<div class="platform-name">' + platformName(platform) + '</div>' +
          '<div class="platform-desc">' + platformDescription(platform) + '</div>';
        scopePlatformTree(card);
        return card;
      };
      var savedDownloadPlatform = function () {
        try {
          var saved = sessionStorage.getItem('luck_download_platform') || '';
          return PLATFORM_ORDER.indexOf(saved) !== -1 ? saved : '';
        } catch (ignore) {
          return '';
        }
      };
      var updateDownloadHref = function (platform) {
        if (!download || PLATFORM_ORDER.indexOf(platform) === -1 || !resourcesBaseHref) return;
        try {
          var target = new URL(resourcesBaseHref, window.location.href);
          // Open the filtered resource catalog so customers can see and choose
          // a client. Each card's own Download button points to the binary.
          target.searchParams.set('platform', platform);
          target.searchParams.set('lang', platformLocale);
          target.hash = 'platform-' + platform;
          download.setAttribute('href', target.toString());
          download.removeAttribute('target');
          download.dataset.luckPlatform = platform;
          var accessibleLabel = localizedDownloadLabel + ': ' + platformName(platform);
          download.setAttribute('aria-label', accessibleLabel);
          download.setAttribute('title', accessibleLabel);
        } catch (ignore) {
          download.setAttribute('href', resourcesBaseHref);
        }
      };
      var selectDownloadPlatform = function (platform, focus) {
        if (PLATFORM_ORDER.indexOf(platform) === -1) return;
        selectedDownloadPlatform = platform;
        try { sessionStorage.setItem('luck_download_platform', platform); } catch (ignore) {}
        document.querySelectorAll('.luck-download-section .platform-card[data-luck-platform]').forEach(function (card) {
          var selected = card.dataset.luckPlatform === platform;
          card.classList.toggle('active', selected);
          card.setAttribute('aria-selected', selected ? 'true' : 'false');
          card.setAttribute('tabindex', selected ? '0' : '-1');
          if (selected && focus) card.focus();
        });
        updateDownloadHref(platform);
      };
      var bindPlatformCard = function (card, platform) {
        card.dataset.luckPlatform = platform;
        card.setAttribute('role', 'option');
        var description = card.querySelector('.platform-desc');
        var localizedDescription = platformDescription(platform);
        if (description && localizedDescription) description.textContent = localizedDescription;
        card.setAttribute('aria-label', platformName(platform) +
          (description && description.textContent ? ': ' + description.textContent.trim() : ''));
        if (card.dataset.luckPlatformBound === '1') return;
        card.dataset.luckPlatformBound = '1';
        card.addEventListener('click', function () {
          selectDownloadPlatform(platform, false);
          window.setTimeout(function () { selectDownloadPlatform(platform, false); }, 0);
        });
        card.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            card.click();
            return;
          }
          var currentIndex = PLATFORM_ORDER.indexOf(platform);
          var nextIndex = currentIndex;
          if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % PLATFORM_ORDER.length;
          else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (currentIndex + PLATFORM_ORDER.length - 1) % PLATFORM_ORDER.length;
          else if (event.key === 'Home') nextIndex = 0;
          else if (event.key === 'End') nextIndex = PLATFORM_ORDER.length - 1;
          else return;
          event.preventDefault();
          selectDownloadPlatform(PLATFORM_ORDER[nextIndex], true);
        });
      };
      var syncDownloadPlatforms = function (platformCards) {
        if (!platformCards) return;
        platformCards.setAttribute('role', 'listbox');
        platformCards.setAttribute('aria-label', platformCopy.group);
        var cards = {};
        Array.prototype.forEach.call(platformCards.children, function (card) {
          PLATFORM_ORDER.forEach(function (platform) {
            if (!cards[platform] && card.classList && card.classList.contains(platform)) cards[platform] = card;
          });
        });
        ['macos', 'linux'].forEach(function (platform) {
          if (!cards[platform]) cards[platform] = createPlatformCard(platform);
        });
        PLATFORM_ORDER.forEach(function (platform, index) {
          var card = cards[platform];
          if (!card) return;
          var current = platformCards.children[index] || null;
          if (current !== card) platformCards.insertBefore(card, current);
          bindPlatformCard(card, platform);
        });
        if (!selectedDownloadPlatform) {
          selectedDownloadPlatform = savedDownloadPlatform();
          if (!selectedDownloadPlatform) {
            var active = platformCards.querySelector('.platform-card.active[data-luck-platform]');
            selectedDownloadPlatform = active ? active.dataset.luckPlatform : 'windows';
          }
        }
        selectDownloadPlatform(selectedDownloadPlatform, false);
      };
      var syncDownloadPlacement = function () {
        if (!download) return;
        var path = window.location.pathname.replace(/\/+$/, '') || '/';
        if (path === '/login' || path === '/register') {
          download.hidden = true;
          return;
        }
        var downloadSection = document.querySelector('.clients-section');
        if (!downloadSection) {
          download.hidden = true;
          return;
        }
        downloadSection.classList.add('luck-download-section');
        var toolbar = downloadSection.querySelector('.luck-download-toolbar');
        var platformCards = downloadSection.querySelector('.platform-cards');
        syncDownloadPlatforms(platformCards);
        if (!toolbar) {
          toolbar = document.createElement('div');
          toolbar.className = 'luck-download-toolbar';
          downloadSection.insertBefore(toolbar, platformCards || downloadSection.firstChild);
        }
        if (download.parentElement !== toolbar) toolbar.appendChild(download);
        download.hidden = false;
      };
      banner.addEventListener('click', open);
      close.addEventListener('click', dismiss);
      modal.addEventListener('click', function (event) {
        if (event.target === modal) dismiss();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) dismiss();
      });
      var refreshTimer = 0;
      var runRefresh = function () {
        syncClashIcons();
        syncIconVisibility();
        syncPlanNameFlags();
        syncDownloadPlacement();
        checkEligibility(false);
      };
      var scheduleRefresh = function () {
        if (refreshTimer) return;
        /* Run once at the leading edge so a continuously mutating Vue tree
           cannot postpone macOS/Linux and local icon hydration forever. The
           bounded trailing pass catches nodes appended during that run. */
        refreshTimer = window.setTimeout(function () {
          refreshTimer = 0;
          runRefresh();
        }, 120);
        runRefresh();
      };
      if (document.body && window.MutationObserver) {
        /* Vue Teleport mounts subscription dialogs beside #app. Observe body
           so the dialog icon/fallback pass also runs for teleported content. */
        new MutationObserver(scheduleRefresh).observe(document.body, { childList: true, subtree: true });
      }
      window.addEventListener('popstate', scheduleRefresh);
      window.addEventListener('pageshow', scheduleRefresh);
      window.addEventListener('resize', scheduleRefresh);
      window.addEventListener('storage', function (event) {
        if (event.key === 'v2board_token') checkEligibility(true);
      });
      window.setTimeout(scheduleRefresh, 0);
    }());
  </script>
  <script>
    (function () {
      var card = document.getElementById('luck-telegram-card');
      var status = document.getElementById('luck-telegram-status');
      var description = document.getElementById('luck-telegram-description');
      var resellerNote = document.getElementById('luck-telegram-reseller-note');
      var primary = document.getElementById('luck-telegram-primary');
      var primaryLabel = primary && primary.querySelector('span');
      var unavailable = document.getElementById('luck-telegram-unavailable');
      var footnote = document.getElementById('luck-telegram-footnote');
      var refresh = document.getElementById('luck-telegram-refresh');
      var refreshLabel = refresh && refresh.querySelector('span');
      var app = document.getElementById('app');
      var parking = document.getElementById('luck-telegram-card-parking');
      if (!card || !status || !primary || !refresh || !parking || card.dataset.bound === '1') return;
      card.dataset.bound = '1';

      var ENDPOINT = '/api/v1/user/telegram/getBotInfo';
      var copyByLocale = {
        'vi-VN': {
          loading: 'Đang kiểm tra', linked: 'Đã liên kết', unlinked: 'Chưa liên kết', unavailable: 'Chưa khả dụng', error: 'Không thể tải',
          description: 'Nhận thông báo tài khoản',
          reseller: 'Công cụ quản lý khách hàng và gói dịch vụ cũng đã được bật cho tài khoản cộng tác viên của bạn.',
          link: 'Liên kết ngay', open: 'Mở Telegram', refresh: 'Làm mới',
          refreshAria: 'Làm mới trạng thái Telegram', unavailableMessage: 'Bot Telegram hiện chưa khả dụng.',
          footnote: 'Không cần nhập lệnh hoặc sao chép liên kết thủ công.'
        },
        'en-US': {
          loading: 'Checking', linked: 'Connected', unlinked: 'Not connected', unavailable: 'Unavailable', error: 'Could not load',
          description: 'Account notifications',
          reseller: 'Customer and plan management tools are also enabled for your reseller account.',
          link: 'Connect now', open: 'Open Telegram', refresh: 'Refresh',
          refreshAria: 'Refresh Telegram status', unavailableMessage: 'The Telegram bot is currently unavailable.',
          footnote: 'No commands or manual link copying required.'
        },
        'zh-CN': {
          loading: '正在检查', linked: '已关联', unlinked: '未关联', unavailable: '暂不可用', error: '加载失败',
          description: '接收账户通知',
          reseller: '您的合作伙伴账户还可使用客户与套餐管理工具。',
          link: '立即关联', open: '打开 Telegram', refresh: '刷新',
          refreshAria: '刷新 Telegram 状态', unavailableMessage: 'Telegram 机器人目前不可用。',
          footnote: '无需输入命令或手动复制链接。'
        },
        'zh-TW': {
          loading: '正在檢查', linked: '已連結', unlinked: '未連結', unavailable: '暫時無法使用', error: '載入失敗',
          description: '接收帳戶通知',
          reseller: '您的合作夥伴帳戶亦已啟用客戶與方案管理工具。',
          link: '立即連結', open: '開啟 Telegram', refresh: '重新整理',
          refreshAria: '重新整理 Telegram 狀態', unavailableMessage: 'Telegram 機器人目前無法使用。',
          footnote: '無需輸入指令或手動複製連結。'
        },
        'ja-JP': {
          loading: '確認中', linked: '連携済み', unlinked: '未連携', unavailable: '利用できません', error: '読み込めません',
          description: 'アカウント通知を受信',
          reseller: '代理店アカウントでは、顧客とプランの管理ツールも利用できます。',
          link: '今すぐ連携', open: 'Telegram を開く', refresh: '更新',
          refreshAria: 'Telegram の状態を更新', unavailableMessage: 'Telegram ボットは現在利用できません。',
          footnote: 'コマンド入力やリンクの手動コピーは不要です。'
        },
        'ko-KR': {
          loading: '확인 중', linked: '연결됨', unlinked: '연결되지 않음', unavailable: '사용할 수 없음', error: '불러올 수 없음',
          description: '계정 알림 받기',
          reseller: '리셀러 계정에는 고객 및 요금제 관리 도구도 활성화됩니다.',
          link: '지금 연결', open: 'Telegram 열기', refresh: '새로고침',
          refreshAria: 'Telegram 상태 새로고침', unavailableMessage: '현재 Telegram 봇을 사용할 수 없습니다.',
          footnote: '명령어 입력이나 링크 수동 복사가 필요하지 않습니다.'
        },
        'fa-IR': {
          loading: 'در حال بررسی', linked: 'متصل', unlinked: 'متصل نشده', unavailable: 'در دسترس نیست', error: 'بارگذاری نشد',
          description: 'دریافت اعلان‌های حساب',
          reseller: 'ابزارهای مدیریت مشتری و طرح نیز برای حساب همکار فروش شما فعال است.',
          link: 'اتصال اکنون', open: 'باز کردن تلگرام', refresh: 'تازه‌سازی',
          refreshAria: 'تازه‌سازی وضعیت تلگرام', unavailableMessage: 'ربات تلگرام در حال حاضر در دسترس نیست.',
          footnote: 'نیازی به وارد کردن دستور یا کپی دستی پیوند نیست.'
        },
        'ru-RU': {
          loading: 'Проверяем', linked: 'Подключено', unlinked: 'Не подключено', unavailable: 'Недоступно', error: 'Не удалось загрузить',
          description: 'Уведомления об аккаунте',
          reseller: 'Для вашего партнёрского аккаунта также доступны инструменты управления клиентами и тарифами.',
          link: 'Подключить', open: 'Открыть Telegram', refresh: 'Обновить',
          refreshAria: 'Обновить статус Telegram', unavailableMessage: 'Telegram-бот сейчас недоступен.',
          footnote: 'Вводить команды или копировать ссылку вручную не нужно.'
        }
      };

      var localeAliases = { vi: 'vi-VN', en: 'en-US', zh: 'zh-CN', ja: 'ja-JP', ko: 'ko-KR', fa: 'fa-IR', ru: 'ru-RU' };
      var currentCopy = copyByLocale['vi-VN'];
      var activeRequest = 0;
      var lastRequestKey = '';
      var refreshTimer = 0;
      var bindingRefreshTimer = 0;
      var placementObserver = null;

      var selectedLocale = function () {
        var locale = String((window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || document.documentElement.lang || 'vi-VN');
        return copyByLocale[locale] ? locale : (localeAliases[locale.split('-')[0].toLowerCase()] || 'vi-VN');
      };
      var normalizeToken = function () {
        try {
          var token = localStorage.getItem('v2board_token') || '';
          if (token.charAt(0) === '"') token = JSON.parse(token);
          return typeof token === 'string' ? token.trim() : '';
        } catch (ignore) {
          return '';
        }
      };
      var isDashboardRoute = function () {
        return (window.location.pathname.replace(/\/+$/, '') || '/') === '/dashboard';
      };
      var cardIsMounted = function () {
        var header = card.parentElement;
        return !!(header && header.classList.contains('luck-subscription-header') &&
          header.parentElement && header.parentElement.classList.contains('subscription-cards-section'));
      };
      var safeTelegramUrl = function (value) {
        try {
          var url = new URL(String(value || ''));
          var host = url.hostname.toLowerCase();
          if (url.protocol !== 'https:' || host !== 't.me' || url.username || url.password || url.port) return '';
          return url.toString();
        } catch (ignore) {
          return '';
        }
      };
      var setText = function (node, value) { if (node) node.textContent = value; };
      var clearBindingRefresh = function () {
        window.clearTimeout(bindingRefreshTimer);
        bindingRefreshTimer = 0;
      };
      var scheduleBindingRefresh = function (expiresIn) {
        clearBindingRefresh();
        expiresIn = Number(expiresIn);
        if (!isFinite(expiresIn) || expiresIn <= 0) return;
        var delay = Math.min(Math.max(30, expiresIn - 30) * 1000, 2147483647);
        bindingRefreshTimer = window.setTimeout(function () {
          bindingRefreshTimer = 0;
          if (!card.hidden) loadBotInfo(true);
        }, delay);
      };
      var applyCopy = function () {
        currentCopy = copyByLocale[selectedLocale()];
        setText(description, currentCopy.description);
        setText(resellerNote, currentCopy.reseller);
        setText(refreshLabel, currentCopy.refresh);
        setText(unavailable, currentCopy.unavailableMessage);
        setText(footnote, currentCopy.footnote);
        refresh.setAttribute('aria-label', currentCopy.refreshAria);
        refresh.setAttribute('title', currentCopy.refreshAria);
      };
      var setState = function (name, statusText) {
        card.dataset.state = name;
        card.setAttribute('aria-busy', name === 'loading' ? 'true' : 'false');
        refresh.disabled = name === 'loading';
        setText(status, statusText);
      };
      var beginLoading = function () {
        primary.hidden = true;
        primary.removeAttribute('href');
        unavailable.hidden = true;
        resellerNote.hidden = true;
        setState('loading', currentCopy.loading);
      };
      var showUnavailable = function (stateName, statusText) {
        primary.hidden = true;
        primary.removeAttribute('href');
        unavailable.hidden = false;
        resellerNote.hidden = true;
        setState(stateName, statusText);
      };
      var renderBotInfo = function (data) {
        data = data && typeof data === 'object' ? data : {};
        if (data.enabled === false) {
          showUnavailable('unavailable', currentCopy.unavailable);
          return;
        }
        var bindUrl = safeTelegramUrl(data.bind_url);
        var linked = data.linked === true;
        resellerNote.hidden = !(data.capabilities && data.capabilities.reseller === true);
        if (!bindUrl) {
          showUnavailable('unavailable', linked ? currentCopy.linked : currentCopy.unavailable);
          return;
        }
        unavailable.hidden = true;
        primary.href = bindUrl;
        primary.hidden = false;
        setText(primaryLabel, linked ? currentCopy.open : currentCopy.link);
        setState(linked ? 'linked' : 'unlinked', linked ? currentCopy.linked : currentCopy.unlinked);
        scheduleBindingRefresh(data.binding_expires_in);
      };
      var loadBotInfo = function (force) {
        var token = normalizeToken();
        if (!token || !isDashboardRoute() || card.hidden || !cardIsMounted()) {
          parkCard();
          return;
        }
        var requestKey = token + '|' + selectedLocale();
        if (!force && requestKey === lastRequestKey) return;
        lastRequestKey = requestKey;
        var requestId = ++activeRequest;
        clearBindingRefresh();
        applyCopy();
        beginLoading();
        fetch(ENDPOINT, {
          method: 'GET', credentials: 'same-origin', cache: 'no-store',
          headers: { 'Accept': 'application/json', 'Authorization': token }
        }).then(function (response) {
          if (response.status === 401 || response.status === 403) {
            parkCard();
            return null;
          }
          if (!response.ok) throw new Error('Telegram status request failed');
          return response.json();
        }).then(function (payload) {
          if (requestId !== activeRequest) return;
          renderBotInfo(payload && payload.data);
        }).catch(function () {
          if (requestId !== activeRequest) return;
          lastRequestKey = '';
          showUnavailable('error', currentCopy.error);
        });
      };
      var observeApp = function () {
        if (placementObserver && app) {
          placementObserver.observe(app, { childList: true, subtree: true });
        }
      };
      var moveCard = function (parent, beforeNode) {
        if (!parent) return;
        if (placementObserver) placementObserver.disconnect();
        parent.insertBefore(card, beforeNode || null);
        observeApp();
      };
      var parkCard = function () {
        activeRequest += 1;
        lastRequestKey = '';
        clearBindingRefresh();
        card.hidden = true;
        card.classList.remove('luck-telegram-card--subscription');
        if (card.parentElement !== parking) moveCard(parking, null);
      };
      var firstVisible = function (selector) {
        var nodes = document.querySelectorAll(selector);
        for (var index = 0; index < nodes.length; index += 1) {
          if (nodes[index].getClientRects().length > 0) return nodes[index];
        }
        return null;
      };
      var syncPlacement = function (force) {
        if (!isDashboardRoute() || !normalizeToken()) {
          parkCard();
          return;
        }
        var subscriptionSection = firstVisible('.subscription-cards-section');
        var sectionTitle = null;
        var subscriptionGrid = null;
        var header = null;
        if (subscriptionSection) {
          for (var index = 0; index < subscriptionSection.children.length; index += 1) {
            if (subscriptionSection.children[index].classList.contains('section-title')) {
              sectionTitle = subscriptionSection.children[index];
            } else if (subscriptionSection.children[index].classList.contains('subscription-grid')) {
              subscriptionGrid = subscriptionSection.children[index];
            } else if (subscriptionSection.children[index].classList.contains('luck-subscription-header')) {
              header = subscriptionSection.children[index];
            }
          }
          if (header) {
            for (var headerIndex = 0; headerIndex < header.children.length; headerIndex += 1) {
              if (header.children[headerIndex].classList.contains('section-title')) {
                sectionTitle = header.children[headerIndex];
                break;
              }
            }
          }
        }
        if (!subscriptionSection || !sectionTitle || !subscriptionGrid) {
          parkCard();
          return;
        }
        var placementIsCurrent = header && header.parentElement === subscriptionSection &&
          header.nextElementSibling === subscriptionGrid && sectionTitle.parentElement === header &&
          card.parentElement === header && card.previousElementSibling === sectionTitle;
        if (!placementIsCurrent) {
          if (placementObserver) placementObserver.disconnect();
          if (!header) {
            header = document.createElement('div');
            header.className = 'luck-subscription-header';
          }
          subscriptionSection.insertBefore(header, subscriptionGrid);
          if (sectionTitle.parentElement !== header) header.insertBefore(sectionTitle, header.firstChild);
          if (card.parentElement !== header || card.previousElementSibling !== sectionTitle) {
            header.insertBefore(card, sectionTitle.nextElementSibling);
          }
          observeApp();
        }
        card.classList.add('luck-telegram-card--subscription');
        card.hidden = false;
        applyCopy();
        loadBotInfo(force === true);
      };
      var schedulePlacement = function () {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(function () { syncPlacement(false); }, 100);
      };

      refresh.addEventListener('click', function () { loadBotInfo(true); });
      if (app && window.MutationObserver) {
        placementObserver = new MutationObserver(schedulePlacement);
        observeApp();
      }
      window.addEventListener('popstate', schedulePlacement);
      window.addEventListener('pageshow', function () { syncPlacement(true); });
      window.addEventListener('focus', function () {
        if (!card.hidden && card.dataset.state !== 'loading') {
          window.setTimeout(function () {
            if (!card.hidden && card.dataset.state !== 'loading') loadBotInfo(true);
          }, 500);
        }
      });
      window.addEventListener('storage', function (event) {
        if (event.key === 'v2board_token') syncPlacement(true);
      });
      window.setTimeout(function () { syncPlacement(true); }, 0);
    }());
  </script>
  <div class="luck-language-picker" aria-label="{{ __('Language selector') }}">
    <label for="luck-language-select">
      <svg class="luck-portable-icon-svg" data-luck-icon="language" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" style="display:block">
        <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0c2.2-2.4 3.3-5.4 3.3-9S14.2 5.4 12 3m0 18c-2.2-2.4-3.3-5.4-3.3-9S9.8 5.4 12 3M3.5 9h17m-17 6h17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </label>
    <select id="luck-language-select" aria-label="{{ __('Choose language') }}">
      <option value="vi-VN">Tiếng Việt</option>
      <option value="en-US">English</option>
      <option value="zh-CN">简体中文</option>
      <option value="zh-TW">繁體中文</option>
      <option value="ja-JP">日本語</option>
      <option value="ko-KR">한국어</option>
      <option value="fa-IR">فارسی</option>
      <option value="ru-RU">Русский</option>
    </select>
  </div>
  <script>
    (function () {
      var select = document.getElementById('luck-language-select');
      if (!select) return;
      var picker = select.closest('.luck-language-picker');
      var app = document.getElementById('app');
      var current = (window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || 'vi-VN';
      select.value = current;

      var syncDocumentDirection = function (locale) {
        var normalized = String(locale || '').toLowerCase();
        document.documentElement.dir = normalized.indexOf('fa') === 0 ? 'rtl' : 'ltr';
      };
      syncDocumentDirection(current);

      var firstVisible = function (selector) {
        var candidates = document.querySelectorAll(selector);
        for (var index = 0; index < candidates.length; index += 1) {
          if (candidates[index].getClientRects().length > 0) return candidates[index];
        }
        return null;
      };
      var syncPickerPlacement = function () {
        if (!picker) return;
        var path = window.location.pathname.replace(/\/+$/, '') || '/';
        var authPage = path === '/login' || path === '/register';
        var oldTabletHosts = document.querySelectorAll('.luck-language-host--tablet');
        for (var hostIndex = 0; hostIndex < oldTabletHosts.length; hostIndex += 1) {
          oldTabletHosts[hostIndex].classList.remove('luck-language-host--tablet');
        }
        picker.classList.remove('luck-language-picker--inline', 'luck-language-picker--mobile', 'luck-language-picker--tablet');
        if (authPage) {
          if (picker.parentElement !== document.body) document.body.appendChild(picker);
          return;
        }

        if (window.matchMedia('(max-width: 768px)').matches) {
          var mobileHeader = firstVisible('.mobile-header');
          if (mobileHeader) {
            picker.classList.add('luck-language-picker--mobile');
            if (picker.parentElement !== mobileHeader) mobileHeader.appendChild(picker);
            return;
          }
        } else if (window.matchMedia('(max-width: 1180px)').matches) {
          var tabletHeader = firstVisible('.header-content');
          if (tabletHeader) {
            tabletHeader.classList.add('luck-language-host--tablet');
            picker.classList.add('luck-language-picker--tablet');
            if (picker.parentElement !== tabletHeader) tabletHeader.appendChild(picker);
            return;
          }
        } else {
          var headerActions = firstVisible('.header-actions');
          if (headerActions) {
            picker.classList.add('luck-language-picker--inline');
            if (picker.parentElement !== headerActions) headerActions.insertBefore(picker, headerActions.firstElementChild);
            return;
          }
        }

        if (picker.parentElement !== document.body) document.body.appendChild(picker);
      };

      select.addEventListener('change', function () {
        syncDocumentDirection(select.value);
        if (window.__LUCK_SET_LOCALE__ && window.__LUCK_SET_LOCALE__(select.value) !== false) return;
        try {
          document.cookie = 'luck_locale=' + encodeURIComponent(select.value) + '; path=/; max-age=31536000; SameSite=Lax';
          document.cookie = 'luck_locale_manual=1; path=/; max-age=31536000; SameSite=Lax';
          window.localStorage.setItem('luck_locale', select.value);
          window.localStorage.setItem('luck_locale_manual', '1');
        } catch (ignoreLocaleStorage) {}
        window.location.reload();
      });

      var schedulePickerSync = function () { window.requestAnimationFrame(syncPickerPlacement); };
      if (app && window.MutationObserver) {
        new MutationObserver(schedulePickerSync).observe(app, { childList: true, subtree: true });
      }
      window.addEventListener('popstate', schedulePickerSync);
      window.addEventListener('pageshow', schedulePickerSync);
      window.addEventListener('resize', schedulePickerSync);
      window.setTimeout(syncPickerPlacement, 0);
    }());
  </script>
  @php
    // An installed plugin record is the authority for its lifecycle. Legacy
    // settings remain available only to installations that have never created
    // the corresponding plugin record; explicitly disabling a plugin must not
    // leave its old support widget active.
    $supportPluginStates = \App\Models\Plugin::query()
      ->whereIn('code', ['crisp', 'messenger'])
      ->pluck('is_enabled', 'code');

    $crispInstalled = $supportPluginStates->has('crisp');
    $crispFallback = $crispInstalled
      ? ''
      : (string) admin_setting('crisp_website_id', env('CRISP_WEBSITE_ID', ''));
    $crispWebsiteId = $crispInstalled && !(bool) $supportPluginStates->get('crisp')
      ? ''
      : trim((string) \App\Services\Plugin\HookManager::filter('theme.support.crisp.website_id', $crispFallback));

    $messengerInstalled = $supportPluginStates->has('messenger');
    $messengerFallback = $messengerInstalled
      ? ''
      : (string) admin_setting('messenger_page_username', env('MESSENGER_PAGE_USERNAME', ''));
    $messengerUsername = $messengerInstalled && !(bool) $supportPluginStates->get('messenger')
      ? ''
      : trim((string) \App\Services\Plugin\HookManager::filter('theme.support.messenger.page_username', $messengerFallback));
    $messengerPageIdFallback = $messengerInstalled
      ? ''
      : (string) admin_setting('messenger_page_id', env('MESSENGER_PAGE_ID', ''));
    $messengerPageId = $messengerInstalled && !(bool) $supportPluginStates->get('messenger')
      ? ''
      : trim((string) \App\Services\Plugin\HookManager::filter('theme.support.messenger.page_id', $messengerPageIdFallback));
  @endphp
  <script>
    (function () {
      var websiteId = @json($crispWebsiteId);
      if (!websiteId || !/^[0-9a-f-]{36}$/i.test(websiteId) || window.__luckCrispLoaded) return;
      window.__luckCrispLoaded = true;
      window.$crisp = window.$crisp || [];
      window.CRISP_RUNTIME_CONFIG = window.CRISP_RUNTIME_CONFIG || {};
      window.CRISP_RUNTIME_CONFIG.locale = String((window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || 'vi-VN').split('-')[0];
      window.CRISP_WEBSITE_ID = websiteId;
      var script = document.createElement('script');
      script.src = 'https://client.crisp.chat/l.js';
      script.async = true;
      script.setAttribute('data-luck-integration', 'crisp');
      document.head.appendChild(script);
    }());
  </script>
  @if($messengerUsername !== '' && preg_match('/^[A-Za-z0-9._-]{3,100}$/', $messengerUsername))
    <a class="luck-messenger-support" href="https://m.me/{{ rawurlencode($messengerUsername) }}" target="_blank" rel="noopener noreferrer" dir="{{ app()->getLocale() === 'fa-IR' ? 'rtl' : 'ltr' }}" aria-label="{{ __('Chat with support on Messenger') }}" title="{{ __('Chat with support on Messenger') }}">f</a>
  @endif
  @if($messengerPageId !== '' && preg_match('/^\d{5,30}$/', $messengerPageId))
    <div id="fb-root"></div>
    <div class="fb-customerchat" page_id="{{ $messengerPageId }}" attribution="setup_tool" data-luck-integration="messenger" data-luck-page-id="{{ $messengerPageId }}"></div>
    <script>
      (function (document, tag, id) {
        if (document.getElementById(id)) return;
        var script = document.createElement(tag);
        script.id = id;
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.src = 'https://connect.facebook.net/en_US/sdk/xfbml.customerchat.js';
        var first = document.getElementsByTagName(tag)[0];
        first.parentNode.insertBefore(script, first);
      }(document, 'script', 'facebook-jssdk'));
    </script>
  @endif
  <script>
    window.V2BOARD_CONFIG = window.V2BOARD_CONFIG || {};
    window.V2BOARD_CONFIG.DEFAULT_API_URL = window.location.origin;
    window.V2BOARD_CONFIG.APP_TITLE = "ZaoGuang Service";
    document.title = window.V2BOARD_CONFIG.APP_TITLE;
  </script>
</body>
</html>
