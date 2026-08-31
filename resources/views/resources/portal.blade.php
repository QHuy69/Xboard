<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f766e">
  <title>{{ $config['title'] }} · {{ $appName }}</title>
  <style>
    :root{color-scheme:light;--ink:#102a2a;--muted:#647474;--line:#dce9e7;--brand:#0f9f8f;--brand-dark:#08786d;--soft:#edf9f7;--card:#fff;--shadow:0 20px 60px rgba(15,73,70,.12)}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;min-height:100vh;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at 10% 5%,#d8f5ef 0,transparent 28rem),linear-gradient(180deg,#f7fbfb 0,#eef5f5 100%)}
    a{color:inherit}.shell{width:min(1120px,calc(100% - 32px));margin:0 auto}.topbar{display:flex;align-items:center;justify-content:space-between;padding:26px 0}.brand{display:flex;align-items:center;gap:12px;text-decoration:none;font-weight:800}.brand-mark{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(135deg,#19bba7,#1478d4);color:#fff;box-shadow:0 10px 24px rgba(15,159,143,.26)}.brand-mark img{width:100%;height:100%;object-fit:contain;border-radius:14px}.back{font-size:14px;text-decoration:none;color:#48605f;padding:10px 14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.72)}
    .hero{padding:72px 0 46px;text-align:center}.eyebrow{display:inline-flex;gap:8px;align-items:center;border:1px solid #bfe8e1;background:rgba(255,255,255,.76);color:var(--brand-dark);border-radius:999px;padding:8px 13px;font-size:13px;font-weight:750}.hero h1{font-size:clamp(34px,6vw,62px);line-height:1.05;letter-spacing:-.045em;margin:20px auto 16px;max-width:820px}.hero p{max-width:680px;margin:0 auto;color:var(--muted);font-size:clamp(16px,2.5vw,20px);line-height:1.7}.notice{margin:28px auto 0;max-width:780px;padding:13px 18px;border-radius:14px;background:#fff8df;border:1px solid #f1df9a;color:#715a08;font-size:14px}
    .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;padding:18px 0 76px;scroll-margin-top:24px;outline:none}.card{position:relative;display:flex;flex-direction:column;min-height:310px;padding:26px;border:1px solid rgba(189,218,214,.85);border-radius:24px;background:rgba(255,255,255,.88);box-shadow:var(--shadow);backdrop-filter:blur(12px);overflow:hidden}.card:before{content:"";position:absolute;inset:0 0 auto;height:4px;background:linear-gradient(90deg,#11a895,#3f8ce8)}.platform-icon{width:54px;height:54px;border-radius:17px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,#0f9f8f,#3c8be1);box-shadow:0 12px 28px rgba(27,125,139,.22)}.platform-icon svg{width:28px;height:28px}.platform{margin-top:22px;color:var(--brand-dark);font-size:12px;text-transform:uppercase;letter-spacing:.12em;font-weight:800}.card h2{font-size:22px;letter-spacing:-.02em;margin:7px 0 8px}.version{display:inline-flex;align-self:flex-start;background:var(--soft);color:var(--brand-dark);font-size:12px;font-weight:750;border-radius:999px;padding:6px 9px;margin-bottom:14px}.description{margin:0;color:var(--muted);line-height:1.65;font-size:14px}.download{margin-top:auto;display:flex;align-items:center;justify-content:center;gap:9px;text-decoration:none;color:#fff;background:linear-gradient(135deg,var(--brand),#2f88d8);border-radius:14px;padding:14px 18px;font-weight:800;box-shadow:0 12px 24px rgba(15,159,143,.2);transition:.18s transform,.18s box-shadow}.download:hover{transform:translateY(-2px);box-shadow:0 16px 30px rgba(15,159,143,.28)}
    .empty{grid-column:1/-1;text-align:center;padding:58px 24px;border:1px dashed #b7cfcb;border-radius:24px;background:rgba(255,255,255,.7);color:var(--muted)}.footer{display:flex;justify-content:space-between;gap:16px;align-items:center;border-top:1px solid var(--line);padding:26px 0 36px;color:#6f817f;font-size:13px}.footer a{color:var(--brand-dark);font-weight:700;text-decoration:none}
    @media(max-width:860px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.shell{width:min(100% - 22px,1120px)}.topbar{padding:16px 0}.brand span:last-child{font-size:14px}.back{padding:8px 10px}.hero{padding:48px 0 30px}.hero h1{font-size:38px}.grid{grid-template-columns:1fr;gap:14px;padding-bottom:48px}.card{min-height:285px;border-radius:20px;padding:22px}.footer{align-items:flex-start;flex-direction:column}}
  </style>
</head>
<body>
  <div class="shell">
    <header class="topbar">
      <a class="brand" href="/">
        <span class="brand-mark">@if($logo)<img src="{{ $logo }}" alt="">@else ZG @endif</span>
        <span>{{ $appName }}</span>
      </a>
      <a class="back" href="{{ $dashboardUrl }}">{{ $copy['back'] }}</a>
    </header>

    <main>
      <section class="hero">
        <span class="eyebrow">✓ {{ $copy['official'] }}</span>
        <h1>{{ $config['title'] }}</h1>
        <p>{{ $config['subtitle'] }}</p>
        @if($config['notice'])<div class="notice">{{ $config['notice'] }}</div>@endif
      </section>

      @php
        $platformNames = ['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'android' => 'Android', 'ios' => 'iOS', 'other' => $copy['other']];
      @endphp
      <section class="grid" id="{{ $selectedPlatform ? 'platform-' . $selectedPlatform : 'apps' }}" data-selected-platform="{{ $selectedPlatform }}" aria-label="{{ $copy['apps_aria'] }}" tabindex="-1">
        @forelse($apps as $app)
          <article class="card" data-platform="{{ $app['platform'] }}">
            <div class="platform-icon" aria-hidden="true">
              @if($app['platform'] === 'android')
                <svg viewBox="0 0 24 24" fill="none"><path d="M7 9h10v8.5A1.5 1.5 0 0 1 15.5 19h-7A1.5 1.5 0 0 1 7 17.5V9Zm2-3-1.5-2M15 6l1.5-2M8 9a4 4 0 0 1 8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="7" r=".7" fill="currentColor"/><circle cx="14" cy="7" r=".7" fill="currentColor"/></svg>
              @elseif($app['platform'] === 'windows')
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 5.2 11 4v7H3V5.2Zm9-1.35L21 2.5V11h-9V3.85ZM3 12h8v7L3 17.8V12Zm9 0h9v8.5l-9-1.35V12Z"/></svg>
              @elseif(in_array($app['platform'], ['macos','ios'], true))
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16.7 12.7c0-2.6 2.1-3.8 2.2-3.9a4.7 4.7 0 0 0-3.7-2c-1.6-.2-3 .9-3.8.9-.8 0-2-1-3.3-1-1.7 0-3.3 1-4.2 2.5-1.8 3.1-.5 7.7 1.3 10.2.9 1.2 1.9 2.6 3.2 2.5 1.3-.1 1.8-.8 3.4-.8 1.5 0 2 .8 3.4.8 1.4 0 2.3-1.3 3.1-2.5a11 11 0 0 0 1.4-2.9 4.4 4.4 0 0 1-3-3.8ZM14.2 5.1A4.5 4.5 0 0 0 15.3 2a4.6 4.6 0 0 0-3 1.5 4.2 4.2 0 0 0-1.1 3c1.1.1 2.2-.5 3-1.4Z"/></svg>
              @else
                <svg viewBox="0 0 24 24" fill="none"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              @endif
            </div>
            <div class="platform">{{ $platformNames[$app['platform']] ?? strtoupper($app['platform']) }}</div>
            <h2>{{ $app['name'] }}</h2>
            @if($app['version'])<span class="version">{{ $copy['version'] }} {{ $app['version'] }}</span>@endif
            @if($app['description'])<p class="description">{{ $app['description'] }}</p>@endif
            <a class="download" href="{{ $app['download_url'] }}" target="_blank" rel="noopener noreferrer">{{ $copy['download'] }} <span aria-hidden="true">↓</span></a>
          </article>
        @empty
          <div class="empty">{{ $selectedPlatform ? str_replace(':platform', $platformNames[$selectedPlatform], $copy['empty_platform']) : $copy['empty'] }}</div>
        @endforelse
      </section>
    </main>

    <footer class="footer">
      <span>© {{ date('Y') }} {{ $appName }}. {{ $copy['footer'] }}</span>
      <span>@if($config['support_url'])<a href="{{ $config['support_url'] }}">{{ $copy['support'] }}</a> · @endif<a href="/manage">{{ $copy['manage'] }}</a></span>
    </footer>
  </div>
  @if($selectedPlatform)
  <script>
    (function () {
      var target = document.getElementById(@json('platform-' . $selectedPlatform));
      if (!target) return;
      var reveal = function () {
        target.scrollIntoView({ block: 'start' });
        try { target.focus({ preventScroll: true }); } catch (ignore) { target.focus(); }
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', reveal, { once: true });
      else window.requestAnimationFrame(reveal);
      window.addEventListener('pageshow', reveal);
    }());
  </script>
  @endif
</body>
</html>
