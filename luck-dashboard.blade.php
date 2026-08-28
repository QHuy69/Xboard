<!doctype html>
<html lang="auto">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#3b82f6">
  <link rel="icon" type="image/svg+xml" href="/theme/{{$theme}}/favicon.svg">
  <title>ZaoGuang Service</title>
  <link rel="modulepreload" crossorigin href="/theme/{{$theme}}/assets/DM1yaN1X-v2.js">
  <link rel="modulepreload" crossorigin href="/theme/{{$theme}}/assets/BEq_qS6Y-v2.js">
  <link rel="modulepreload" crossorigin href="/theme/{{$theme}}/assets/0I8bmyai.js">
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/DmSyTPzn.css">
  <!-- Keep the shell and dashboard styles render-blocking. Vite normally
       injects these route styles asynchronously, which can leave the page
       permanently unstyled when a preload request is interrupted. -->
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/BbO9A4Tv.css?v=1">
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/BXdzbR5Q.css?v=1">
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/CrZoyNRZ.css?v=1">
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/luck-overrides.css?v=6">
  <style>
    /* The translator runs after the Vue shell mounts. Never hide the app while
       waiting for an optional translation pass: on a slow mobile connection
       that turns a recoverable delay into a black/empty screen. */
    html.luck-i18n-pending #app { visibility: visible; }

    /* This shell is deliberately inline. Even if a mobile browser loses a
       module or stylesheet request mid-load, it sees a recovery screen rather
       than an opaque black page. */
    #luck-bootstrap {
      position: fixed;
      z-index: 2000;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      color: #1e293b;
      background: radial-gradient(circle at 50% 15%, #eff6ff 0%, #f8fafc 46%, #e2e8f0 100%);
      transition: opacity .24s ease, visibility .24s ease;
    }
    html.luck-app-ready #luck-bootstrap {
      visibility: hidden;
      opacity: 0;
      pointer-events: none;
    }
    .luck-bootstrap-card {
      width: min(340px, 100%);
      padding: 28px 24px;
      border: 1px solid rgba(148, 163, 184, .28);
      border-radius: 22px;
      background: rgba(255, 255, 255, .96);
      box-shadow: 0 22px 60px rgba(15, 23, 42, .16);
      text-align: center;
    }
    .luck-bootstrap-spinner {
      display: inline-block;
      width: 32px;
      height: 32px;
      border: 4px solid #dbeafe;
      border-top-color: #3b82f6;
      border-radius: 50%;
      animation: luck-bootstrap-spin .8s linear infinite;
    }
    .luck-bootstrap-card p { margin: 16px 0 0; color: #475569; font: 600 15px/1.5 system-ui, sans-serif; }
    .luck-bootstrap-card button { margin-top: 16px; padding: 10px 18px; border: 0; border-radius: 999px; color: #fff; background: #2563eb; font: 700 14px/1 system-ui, sans-serif; cursor: pointer; }
    @keyframes luck-bootstrap-spin { to { transform: rotate(360deg); } }
  </style>
  <script>
    document.documentElement.classList.add('luck-i18n-pending');
    window.__LUCK_RELEASE_I18N_GUARD__ = function () {
      document.documentElement.classList.remove('luck-i18n-pending');
    };
    window.setTimeout(window.__LUCK_RELEASE_I18N_GUARD__, 1000);
  </script>
  <script>
    /* A stale cached entry chunk can reject a lazy route import. Retry once
       with a cache-busting query so a first visit never requires a manual F5;
       the session flag prevents an endless reload loop if an origin is down. */
    (function () {
      var retryKey = 'luck_chunk_retry_at';
      var isChunkFailure = function (value) {
        var message = value && (value.message || value.reason || value);
        return /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|Loading chunk|ChunkLoadError|Unable to preload CSS/i.test(String(message || ''));
      };
      var retry = function (value, confirmedChunkFailure) {
        if (!confirmedChunkFailure && !isChunkFailure(value)) return;
        var now = Date.now();
        try {
          var previous = Number(sessionStorage.getItem(retryKey) || 0);
          if (previous && now - previous < 15000) return;
          sessionStorage.setItem(retryKey, String(now));
        } catch (ignore) {}
        var url = new URL(window.location.href);
        url.searchParams.set('luck_reload', String(now));
        window.setTimeout(function () { window.location.replace(url.toString()); }, 0);
      };
      window.addEventListener('error', function (event) { retry(event && (event.error || event.message)); });
      window.addEventListener('unhandledrejection', function (event) { retry(event && event.reason); });
      // Vite emits this event before Vue Router can swallow a failed lazy
      // import. Recover only on a real preload failure; ordinary menu clicks
      // remain in-app navigation and never reload the document.
      window.addEventListener('vite:preloadError', function (event) {
        if (event && event.preventDefault) event.preventDefault();
        retry(event && event.payload, true);
      });
      window.addEventListener('luck:route-error', function (event) {
        retry(event && event.detail);
      });
      window.setTimeout(function () {
        try { if (document.getElementById('app') && document.getElementById('app').children.length) sessionStorage.removeItem(retryKey); } catch (ignore) {}
      }, 8000);
    }());
  </script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/luck-entry-v42.js?v=1"></script>
</head>
<body>
  <div id="app"></div>
  <div id="luck-bootstrap" role="status" aria-live="polite">
    <div class="luck-bootstrap-card">
      <span class="luck-bootstrap-spinner" aria-hidden="true"></span>
      <p id="luck-bootstrap-status">Đang mở ZaoGuang Service…</p>
      <button id="luck-bootstrap-retry" type="button" hidden>Thử lại</button>
    </div>
  </div>
  <button id="luck-donate-banner" class="luck-donate-banner" type="button" aria-haspopup="dialog">
    <span class="luck-donate-banner-label">Ủng hộ</span>
  </button>
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
  <script src="/theme/{{$theme}}/i18n-v18.js?v=42"></script>
  <script>
    (function () {
      var app = document.getElementById('app');
      var shell = document.getElementById('luck-bootstrap');
      var status = document.getElementById('luck-bootstrap-status');
      var retryButton = document.getElementById('luck-bootstrap-retry');
      if (!app || !shell || !status || !retryButton) return;
      var language = ((window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || navigator.language || 'vi-VN').replace('_', '-');
      var bootstrapCopy = {
        'vi-VN': { loading: 'Đang mở ZaoGuang Service…', retry: 'Thử lại', failed: 'Kết nối giao diện đang chậm. Hãy thử lại.' },
        'en-US': { loading: 'Opening ZaoGuang Service…', retry: 'Try again', failed: 'The interface is taking too long to connect. Please try again.' },
        'zh-CN': { loading: '正在打开 ZaoGuang Service…', retry: '重试', failed: '界面连接时间过长，请重试。' },
        'zh-TW': { loading: '正在開啟 ZaoGuang Service…', retry: '重試', failed: '介面連線時間過長，請重試。' },
        'ja-JP': { loading: 'ZaoGuang Service を開いています…', retry: '再試行', failed: '画面の接続に時間がかかっています。もう一度お試しください。' },
        'ko-KR': { loading: 'ZaoGuang Service를 여는 중…', retry: '다시 시도', failed: '화면 연결에 시간이 오래 걸리고 있습니다. 다시 시도해 주세요.' },
        'fa-IR': { loading: 'در حال باز کردن ZaoGuang Service…', retry: 'تلاش دوباره', failed: 'اتصال رابط بیش از حد طول کشیده است. دوباره تلاش کنید.' },
        'ru-RU': { loading: 'Открываем ZaoGuang Service…', retry: 'Повторить', failed: 'Подключение интерфейса занимает слишком много времени. Повторите попытку.' }
      };
      var copy = bootstrapCopy[language] || bootstrapCopy['vi-VN'];
      status.textContent = copy.loading;
      retryButton.textContent = copy.retry;
      var retryKey = 'luck_boot_retry_at';
      var ready = false;
      var observer;
      var hasMountedApp = function () {
        return app.children.length > 0 && String(app.textContent || '').trim().length > 20;
      };
      var markReady = function () {
        if (ready) return;
        ready = true;
        document.documentElement.classList.add('luck-app-ready');
        if (observer) observer.disconnect();
        try { sessionStorage.removeItem(retryKey); } catch (ignore) {}
      };
      window.__LUCK_MARK_APP_READY__ = markReady;
      observer = new MutationObserver(function () { if (hasMountedApp()) markReady(); });
      observer.observe(app, { childList: true, characterData: true, subtree: true });
      if (hasMountedApp()) markReady();
      window.setTimeout(function () {
        if (ready || hasMountedApp()) return markReady();
        var now = Date.now();
        try {
          var previous = Number(sessionStorage.getItem(retryKey) || 0);
          if (!previous || now - previous > 30000) {
            sessionStorage.setItem(retryKey, String(now));
            var url = new URL(window.location.href);
            url.searchParams.set('luck_boot', String(now));
            window.location.replace(url.toString());
            return;
          }
        } catch (ignore) {}
        status.textContent = copy.failed;
        retryButton.hidden = false;
      }, 7000);
      retryButton.addEventListener('click', function () {
        try { sessionStorage.removeItem(retryKey); } catch (ignore) {}
        window.location.reload();
      });
    }());
  </script>
  <script>
    (function () {
      var banner = document.getElementById('luck-donate-banner');
      var modal = document.getElementById('luck-donate-modal');
      var close = document.getElementById('luck-donate-close');
      if (!banner || !modal || !close || banner.dataset.bound === '1') return;
      banner.dataset.bound = '1';
      var lang = (window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || document.documentElement.lang || 'vi-VN';
      var labels = {
        'vi-VN': 'Ủng hộ', 'en-US': 'Donate', 'zh-CN': '捐赠', 'zh-TW': '捐贈',
        'ja-JP': '寄付', 'ko-KR': '후원', 'fa-IR': 'حمایت', 'ru-RU': 'Поддержать'
      };
      var label = labels[lang] || labels['vi-VN'];
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
        modal.hidden = false;
        document.body.classList.add('luck-donate-open');
        close.focus();
      };
      var dismiss = function () {
        modal.hidden = true;
        document.body.classList.remove('luck-donate-open');
        banner.focus();
      };
      banner.addEventListener('click', open);
      close.addEventListener('click', dismiss);
      modal.addEventListener('click', function (event) {
        if (event.target === modal) dismiss();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) dismiss();
      });
      window.setTimeout(open, 0);
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
