<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  @php
    $manifestPath = public_path('assets/admin/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    $scripts = [];
    $styles = [];
    $locales = [];
    $assetRoot = realpath(public_path('assets/admin'));
    $resolveAsset = static function ($relative) use ($assetRoot) {
      if (!is_string($relative) || $assetRoot === false) {
        return null;
      }

      $relative = str_replace('\\', '/', trim($relative));
      if (str_starts_with($relative, './')) {
        $relative = substr($relative, 2);
      }
      if (!str_starts_with($relative, 'assets/') || str_contains($relative, '..')) {
        return null;
      }

      $absolute = realpath(public_path('assets/admin/' . $relative));
      $rootPrefix = rtrim($assetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      if ($absolute === false || !is_file($absolute) || !str_starts_with($absolute, $rootPrefix)) {
        return null;
      }

      return $relative;
    };
    // The compiled admin filenames are patched in place for maintained fixes,
    // so their original Vite hashes do not change. Tie every public asset URL
    // to the immutable application build version to prevent browsers and CDNs
    // from keeping an older module (for example the platform-dependent flag
    // emoji) after a release.
    $assetVersion = rawurlencode((string) config('app.version', '1.0.0'));

    if (is_array($entry)) {
      $visited = [];
      $collectAssets = function ($chunkName) use (&$collectAssets, &$manifest, &$visited, &$scripts, &$styles, $resolveAsset) {
        if (isset($visited[$chunkName]) || !isset($manifest[$chunkName]) || !is_array($manifest[$chunkName])) {
          return;
        }

        $visited[$chunkName] = true;
        $chunk = $manifest[$chunkName];

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
          foreach ($chunk['css'] as $cssFile) {
            $cssFile = $resolveAsset($cssFile);
            if ($cssFile !== null) {
              $styles[$cssFile] = $cssFile;
            }
          }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
          foreach ($chunk['imports'] as $import) {
            $collectAssets($import);
          }
        }

        if (!empty($chunk['isEntry']) && !empty($chunk['file'])) {
          $scriptFile = $resolveAsset($chunk['file']);
          if ($scriptFile !== null) {
            $scripts[$scriptFile] = $scriptFile;
          }
        }
      };

      $collectAssets('index.html');
    }

    // A legacy admin build can omit manifest.json. In that case use the exact
    // entry pair declared by its own index.html. Never select JavaScript and
    // CSS independently by mtime because that can mix two retained builds.
    if (count($scripts) === 0 || count($styles) === 0) {
      $fallbackHtmlPath = public_path('assets/admin/index.html');
      $fallbackHtml = is_file($fallbackHtmlPath) ? file_get_contents($fallbackHtmlPath) : false;
      $fallbackScripts = [];
      $fallbackStyles = [];

      if (is_string($fallbackHtml)) {
        preg_match_all(
          '~(?:src|href)=["\'](?:\./|/assets/admin/)?(assets/index-[^"\'?]+\.(?:js|css))(?:\?[^"\']*)?["\']~i',
          $fallbackHtml,
          $fallbackMatches
        );
        foreach ($fallbackMatches[1] ?? [] as $fallbackAsset) {
          $fallbackAsset = $resolveAsset($fallbackAsset);
          if ($fallbackAsset === null) {
            continue;
          }
          if (str_ends_with(strtolower($fallbackAsset), '.js')) {
            $fallbackScripts[$fallbackAsset] = $fallbackAsset;
          } elseif (str_ends_with(strtolower($fallbackAsset), '.css')) {
            $fallbackStyles[$fallbackAsset] = $fallbackAsset;
          }
        }
      }

      if (count($scripts) === 0 && count($fallbackScripts) === 1) {
        $scripts = $fallbackScripts;
      }
      if (count($styles) === 0 && count($fallbackStyles) === 1) {
        $styles = $fallbackStyles;
      }
    }

    foreach (glob(public_path('assets/admin/locales/*.js')) ?: [] as $localeFile) {
      $locales[] = 'locales/' . basename($localeFile);
    }
    sort($locales);

    if (count($scripts) === 0 || count($styles) === 0) {
      abort(503, 'Admin assets are unavailable.');
    }
  @endphp

  @foreach($styles as $css)
    <link rel="stylesheet" crossorigin href="/assets/admin/{{ $css }}?v={{ $assetVersion }}" />
  @endforeach
  @foreach($locales as $locale)
    <script src="/assets/admin/{{ $locale }}?v={{ $assetVersion }}"></script>
  @endforeach
  @foreach($scripts as $js)
    <script type="module" crossorigin src="/assets/admin/{{ $js }}?v={{ $assetVersion }}"></script>
  @endforeach
</head>

<body>
  <div id="root"></div>
</body>

</html>
