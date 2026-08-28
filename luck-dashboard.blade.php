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
  <link rel="stylesheet" crossorigin href="/theme/{{$theme}}/assets/luck-overrides.css?v=4">
  <style>
    /* The translator runs after the Vue shell mounts. Never hide the app while
       waiting for an optional translation pass: on a slow mobile connection
       that turns a recoverable delay into a black/empty screen. */
    html.luck-i18n-pending #app { visibility: visible; }
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
        return /Failed to fetch dynamically imported module|Importing a module script failed|Loading chunk|ChunkLoadError|network/i.test(String(message || ''));
      };
      var retry = function (value) {
        if (!isChunkFailure(value)) return;
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
      window.setTimeout(function () {
        try { if (document.getElementById('app') && document.getElementById('app').children.length) sessionStorage.removeItem(retryKey); } catch (ignore) {}
      }, 8000);
    }());
  </script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/BBbuoBq5-v12.js?v=1"></script>
</head>
<body>
  <div id="app"></div>
  <button id="luck-donate-banner" class="luck-donate-banner" type="button" aria-haspopup="dialog">
    <span class="luck-donate-banner-label">Ủng hộ</span>
  </button>
  <div id="luck-donate-modal" class="luck-donate-modal" hidden role="dialog" aria-modal="true" aria-label="Donation QR code">
    <div class="luck-donate-modal-card">
      <button id="luck-donate-close" class="luck-donate-close" type="button" aria-label="Close">×</button>
      <img class="luck-donate-qr" src="/luck-donate-qr.svg" alt="Donation QR code" decoding="async">
    </div>
  </div>
  <script>window.LUCK_SERVER_LANGUAGES = @json(request()->getLanguages()); window.LUCK_DEFAULT_LANGUAGE = "vi-VN";</script>
  <script src="/theme/{{$theme}}/clients.js"></script>
  <script src="/theme/{{$theme}}/config.js"></script>
  <script src="/theme/{{$theme}}/i18n-v18.js?v=40"></script>
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
      var labelNode = banner.querySelector('.luck-donate-banner-label');
      if (labelNode) labelNode.textContent = label;
      banner.setAttribute('aria-label', label);
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
