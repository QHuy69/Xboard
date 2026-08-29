@php
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#3b82f6">
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
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/luck-overrides.css?v=11">
  <script>
    /* Never change routes in response to a global module/preload event. Some
       mobile WebKit builds emit those events for optional preloads even after
       the app mounted successfully, which previously caused a false reload
       loop and appended luck_reload to the address bar. */
    (function () {
      try {
        sessionStorage.removeItem('luck_chunk_retry_at');
        sessionStorage.removeItem('luck_boot_retry_at');
        var url = new URL(window.location.href);
        var changed = url.searchParams.has('luck_reload') || url.searchParams.has('luck_boot');
        url.searchParams.delete('luck_reload');
        url.searchParams.delete('luck_boot');
        if (changed) window.history.replaceState(window.history.state, '', url.toString());
      } catch (ignore) {}
    }());
  </script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/BBbuoBq5-fresh.js?v=57"></script>
</head>
<body>
  <div id="app"></div>
  <div class="luck-shell-actions">
    <a id="luck-app-download" class="luck-app-download" href="{{ env('LUCK_RESOURCES_URL', 'https://resources.zaoguang-vpn.com') }}" target="_blank" rel="noopener noreferrer" hidden>
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v11m0 0 4-4m-4 4-4-4M5 18v2h14v-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span class="luck-app-download-label">Tải ứng dụng</span>
    </a>
    <button id="luck-donate-banner" class="luck-donate-banner" type="button" aria-haspopup="dialog" hidden>
      <span class="luck-donate-banner-label">Ủng hộ</span>
    </button>
  </div>
  <div id="luck-donate-modal" class="luck-donate-modal" hidden role="dialog" aria-modal="true" aria-labelledby="luck-donate-title">
    <div class="luck-donate-modal-card">
      <div class="luck-donate-modal-content">
        <h2 id="luck-donate-title">Bạn đang sử dụng gói chống lag mùa đứt cáp</h2>
        <p id="luck-donate-message" class="luck-donate-message">Ủng hộ mình tại đây để duy trì đường truyền ổn định.</p>
        <img class="luck-donate-qr" src="/luck-donate-qr.svg" alt="Mã QR ủng hộ" decoding="async">
        <dl class="luck-donate-bank" aria-label="Thông tin tài khoản nhận ủng hộ">
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
  <script src="/theme/{{$theme}}/i18n-v18.js?v=54"></script>
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
      var CLASH_ICON = '/theme/{{$theme}}/assets/luck-clash.svg?v=1';
      var lang = (window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || document.documentElement.lang || 'vi-VN';
      var labels = {
        'vi-VN': 'Ủng hộ', 'en-US': 'Donate', 'zh-CN': '捐赠', 'zh-TW': '捐贈',
        'ja-JP': '寄付', 'ko-KR': '후원', 'fa-IR': 'حمایت', 'ru-RU': 'Поддержать'
      };
      var label = labels[lang] || labels['vi-VN'];
      var downloadLabels = {
        'vi-VN': 'Tải ứng dụng', 'en-US': 'Download app', 'zh-CN': '下载应用', 'zh-TW': '下載應用',
        'ja-JP': 'アプリをダウンロード', 'ko-KR': '앱 다운로드', 'fa-IR': 'دانلود برنامه', 'ru-RU': 'Скачать приложение'
      };
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
      if (downloadLabel) downloadLabel.textContent = downloadLabels[lang] || downloadLabels['vi-VN'];
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
      var syncDownloadPlacement = function () {
        if (!download) return;
        var path = window.location.pathname.replace(/\/+$/, '') || '/';
        if (path === '/login' || path === '/register') {
          download.hidden = true;
          return;
        }
        var shellActions = document.querySelector('.luck-shell-actions');
        if (window.matchMedia('(max-width: 768px)').matches) {
          if (shellActions && download.parentElement !== shellActions) {
            shellActions.insertBefore(download, banner);
          }
          download.hidden = !shellActions;
          return;
        }
        var headerActions = document.querySelector('.header-actions');
        if (!headerActions) {
          download.hidden = true;
          return;
        }
        if (download.parentElement !== headerActions) {
          headerActions.insertBefore(download, headerActions.firstElementChild);
        }
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
      var scheduleRefresh = function () {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(function () {
          syncClashIcons();
          syncDownloadPlacement();
          checkEligibility(false);
        }, 120);
      };
      var app = document.getElementById('app');
      if (app && window.MutationObserver) {
        new MutationObserver(scheduleRefresh).observe(app, { childList: true, subtree: true });
      }
      window.addEventListener('popstate', scheduleRefresh);
      window.addEventListener('pageshow', scheduleRefresh);
      window.addEventListener('resize', scheduleRefresh);
      window.addEventListener('storage', function (event) {
        if (event.key === 'v2board_token') checkEligibility(true);
      });
      window.setTimeout(function () {
        syncClashIcons();
        syncDownloadPlacement();
        checkEligibility(true);
      }, 0);
    }());
  </script>
  <script>
    (function () {
      var websiteId = @json((string) (admin_setting('crisp_website_id', env('CRISP_WEBSITE_ID', ''))));
      if (!websiteId || !/^[0-9a-f-]{36}$/i.test(websiteId) || window.__luckCrispLoaded) return;
      window.__luckCrispLoaded = true;
      window.$crisp = window.$crisp || [];
      window.CRISP_WEBSITE_ID = websiteId;
      var script = document.createElement('script');
      script.src = 'https://client.crisp.chat/l.js';
      script.async = true;
      script.setAttribute('data-luck-integration', 'crisp');
      document.head.appendChild(script);
    }());
  </script>
  <script>
    window.V2BOARD_CONFIG = window.V2BOARD_CONFIG || {};
    window.V2BOARD_CONFIG.DEFAULT_API_URL = window.location.origin;
    window.V2BOARD_CONFIG.APP_TITLE = "ZaoGuang Service";
    document.title = window.V2BOARD_CONFIG.APP_TITLE;
  </script>
</body>
</html>
