<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ResourcePortalController extends Controller
{
    private const PORTAL_LOCALES = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

    private const PORTAL_PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios'];

    /** Increment when the built-in client catalog gains new direct-download entries. */
    private const CLIENT_CATALOG_VERSION = 1;

    public function index(Request $request)
    {
        $locale = $this->normalizePortalLocale((string) $request->query('lang', 'vi-VN'));
        $copy = $this->portalCopy($locale);
        $config = $this->getPortalConfig($locale);
        $allowedPlatforms = ['windows', 'macos', 'linux', 'android', 'ios'];
        $selectedPlatform = strtolower(trim((string) $request->query('platform', '')));
        if (!in_array($selectedPlatform, $allowedPlatforms, true)) {
            $selectedPlatform = '';
        }
        $searchQuery = trim((string) $request->query('q', ''));
        if (mb_strlen($searchQuery) > 80) {
            $searchQuery = mb_substr($searchQuery, 0, 80);
        }
        $sort = strtolower(trim((string) $request->query('sort', 'default')));
        $allowedSorts = ['default', 'name', 'platform', 'version'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'default';
        }

        $apps = collect($config['apps'])
            ->filter(fn (array $app) => $app['enabled'] && $app['download_url'] !== '')
            ->when($searchQuery !== '', function ($items) use ($searchQuery) {
                $needle = mb_strtolower($searchQuery);

                return $items->filter(function (array $app) use ($needle): bool {
                    $haystack = implode(' ', [
                        (string) ($app['name'] ?? ''),
                        (string) ($app['platform'] ?? ''),
                        (string) ($app['version'] ?? ''),
                        (string) ($app['description'] ?? ''),
                    ]);

                    return str_contains(mb_strtolower($haystack), $needle);
                });
            })
            ->when($selectedPlatform !== '', fn ($items) => $items->where('platform', $selectedPlatform))
            ->when($sort === 'name', fn ($items) => $items->sortBy(fn (array $app) => mb_strtolower((string) ($app['name'] ?? ''))))
            ->when($sort === 'platform', function ($items) use ($allowedPlatforms) {
                return $items->sortBy(function (array $app) use ($allowedPlatforms): string {
                    $platformIndex = array_search($app['platform'] ?? '', $allowedPlatforms, true);

                    return sprintf('%02d-%06d', $platformIndex === false ? 99 : $platformIndex, (int) ($app['sort'] ?? 0));
                });
            })
            ->when($sort === 'version', fn ($items) => $items->sortByDesc(function (array $app): string {
                $version = mb_strtolower((string) ($app['version'] ?? ''));

                return (string) preg_replace_callback('/\d+/', fn (array $match): string => str_pad($match[0], 12, '0', STR_PAD_LEFT), $version);
            }))
            ->when($sort === 'default', fn ($items) => $items->sortBy('sort'))
            ->values()
            ->all();

        return view('resources.portal', [
            'config' => $config,
            'apps' => $apps,
            'appName' => admin_setting('app_name', 'ZaoGuang Service'),
            'logo' => admin_setting('logo'),
            'selectedPlatform' => $selectedPlatform,
            'searchQuery' => $searchQuery,
            'sort' => $sort,
            'locale' => $locale,
            'direction' => $locale === 'fa-IR' ? 'rtl' : 'ltr',
            'copy' => $copy,
            'dashboardUrl' => rtrim((string) admin_setting('app_url', config('app.url')), '/') . '/dashboard',
        ]);
    }

    public function manage()
    {
        return view('resources.manage', [
            'appName' => admin_setting('app_name', 'ZaoGuang Service'),
            'securePath' => admin_setting(
                'secure_path',
                admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
            ),
            'portalLocales' => $this->portalLocaleOptions(),
            'newAppTranslations' => collect(self::PORTAL_LOCALES)
                ->mapWithKeys(fn (string $locale) => [$locale => [
                    'name' => $this->portalCopy($locale)['app_default'],
                    'description' => '',
                ]])
                ->all(),
        ]);
    }

    public function fetch()
    {
        return $this->success($this->getEditablePortalConfig());
    }

    /**
     * Redirect a platform CTA to the first enabled, configured client binary.
     * Only URLs saved in the resource portal catalog are eligible; arbitrary
     * redirect targets are never accepted from the request.
     */
    public function download(string $platform, ?string $fingerprint = null)
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, self::PORTAL_PLATFORMS, true)) {
            abort(404);
        }

        $apps = collect($this->getEditablePortalConfig()['apps'])
            ->filter(fn (array $item) => $item['enabled']
                && $item['platform'] === $platform
                && filter_var($item['download_url'], FILTER_VALIDATE_URL))
            ->sortBy('sort');

        $app = $fingerprint === null
            ? $apps->first()
            : $apps->first(fn (array $item) => hash_equals(
                strtolower($fingerprint),
                hash('sha256', $item['download_url'])
            ));

        if (!$app) {
            abort(404, 'Bản tải cho nền tảng này chưa được cấu hình.');
        }

        // The catalog stores direct vendor binary URLs. Keep the application
        // out of the data path: proxying public downloads through PHP would
        // expose workers and bandwidth to unbounded files and redirect-chain
        // SSRF. The opaque fingerprint still prevents selecting an arbitrary
        // configured app from the request.
        return redirect()->away($app['download_url'], 302, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function save(Request $request)
    {
        $rules = [
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:500',
            'notice' => 'nullable|string|max:1000',
            'locales' => 'nullable|array',
            'support_url' => 'nullable|url|max:2048',
            'apps' => 'present|array|max:30',
            'apps.*.name' => 'required|string|max:100',
            'apps.*.platform' => 'required|in:windows,android,macos,ios,linux,other',
            'apps.*.version' => 'nullable|string|max:50',
            'apps.*.download_url' => 'nullable|url|max:2048',
            'apps.*.description' => 'nullable|string|max:300',
            'apps.*.translations' => 'nullable|array',
            'apps.*.enabled' => 'required|boolean',
            'apps.*.sort' => 'required|integer|min:0|max:999',
        ];
        foreach (self::PORTAL_LOCALES as $locale) {
            $rules["locales.{$locale}"] = 'nullable|array';
            $rules["locales.{$locale}.title"] = 'nullable|string|max:120';
            $rules["locales.{$locale}.subtitle"] = 'nullable|string|max:500';
            $rules["locales.{$locale}.notice"] = 'nullable|string|max:1000';
            $rules["apps.*.translations.{$locale}"] = 'nullable|array';
            $rules["apps.*.translations.{$locale}.name"] = 'nullable|string|max:100';
            $rules["apps.*.translations.{$locale}.description"] = 'nullable|string|max:300';
        }
        $validated = $request->validate($rules);

        $legacyPage = [
            'title' => trim((string) $validated['title']),
            'subtitle' => trim((string) ($validated['subtitle'] ?? '')),
            'notice' => trim((string) ($validated['notice'] ?? '')),
        ];
        $localizedPage = [];
        foreach (self::PORTAL_LOCALES as $locale) {
            $submitted = is_array($validated['locales'][$locale] ?? null)
                ? $validated['locales'][$locale]
                : [];
            $title = array_key_exists('title', $submitted)
                ? trim((string) $submitted['title'])
                : $legacyPage['title'];
            $localizedPage[$locale] = [
                'title' => $title !== '' ? $title : $legacyPage['title'],
                'subtitle' => array_key_exists('subtitle', $submitted)
                    ? trim((string) $submitted['subtitle'])
                    : $legacyPage['subtitle'],
                'notice' => array_key_exists('notice', $submitted)
                    ? trim((string) $submitted['notice'])
                    : $legacyPage['notice'],
            ];
        }

        $apps = collect($validated['apps'])
            ->map(function (array $app): array {
                $legacyName = trim((string) $app['name']);
                $legacyDescription = trim((string) ($app['description'] ?? ''));
                $translations = [];
                foreach (self::PORTAL_LOCALES as $locale) {
                    $submitted = is_array($app['translations'][$locale] ?? null)
                        ? $app['translations'][$locale]
                        : [];
                    $name = array_key_exists('name', $submitted)
                        ? trim((string) $submitted['name'])
                        : $legacyName;
                    $translations[$locale] = [
                        'name' => $name !== '' ? $name : $legacyName,
                        'description' => array_key_exists('description', $submitted)
                            ? trim((string) $submitted['description'])
                            : $legacyDescription,
                    ];
                }

                return [
                    // Keep the old fields as a vi-VN mirror so older code and
                    // previously exported configuration remain compatible.
                    'name' => $translations['vi-VN']['name'],
                    'platform' => $app['platform'],
                    'version' => trim((string) ($app['version'] ?? '')),
                    'download_url' => trim((string) ($app['download_url'] ?? '')),
                    'description' => $translations['vi-VN']['description'],
                    'translations' => $translations,
                    'enabled' => (bool) $app['enabled'],
                    'sort' => (int) $app['sort'],
                ];
            })
            ->values()
            ->all();

        $saved = [
            // These mirrors make the new payload readable by older releases.
            'title' => $localizedPage['vi-VN']['title'],
            'subtitle' => $localizedPage['vi-VN']['subtitle'],
            'notice' => $localizedPage['vi-VN']['notice'],
            'locales' => $localizedPage,
            'support_url' => trim((string) ($validated['support_url'] ?? '')),
            'apps' => $apps,
            'client_catalog_version' => self::CLIENT_CATALOG_VERSION,
        ];
        admin_setting(['resource_portal' => $saved]);

        return $this->success($this->getEditablePortalConfig($saved));
    }

    private function getPortalConfig(string $locale): array
    {
        $editable = $this->getEditablePortalConfig();
        $content = $editable['locales'][$locale] ?? $editable['locales']['vi-VN'];

        return [
            'title' => $content['title'],
            'subtitle' => $content['subtitle'],
            'notice' => $content['notice'],
            'support_url' => $editable['support_url'],
            'apps' => collect($editable['apps'])
                ->filter(fn (array $app): bool => ($app['enabled'] ?? false)
                    && in_array(($app['platform'] ?? ''), self::PORTAL_PLATFORMS, true)
                    && filter_var(($app['download_url'] ?? ''), FILTER_VALIDATE_URL) !== false)
                ->map(function (array $app) use ($locale): array {
                $translation = $app['translations'][$locale]
                    ?? $app['translations']['vi-VN']
                    ?? ['name' => $app['name'], 'description' => $app['description']];

                return [
                    'name' => $translation['name'],
                    'platform' => $app['platform'],
                    'version' => $app['version'],
                    'download_url' => route('resources.download', [
                        'platform' => $app['platform'],
                        'fingerprint' => hash('sha256', $app['download_url']),
                    ]),
                    'description' => $translation['description'],
                    'enabled' => $app['enabled'],
                    'sort' => $app['sort'],
                ];
                })->values()->all(),
        ];
    }

    private function getEditablePortalConfig(?array $storedOverride = null): array
    {
        $stored = $storedOverride ?? admin_setting('resource_portal', []);
        $stored = is_array($stored) ? $stored : [];
        $viCopy = $this->portalCopy('vi-VN');
        $legacyPage = [
            'title' => trim((string) ($stored['title'] ?? $viCopy['title'])),
            'subtitle' => trim((string) ($stored['subtitle'] ?? $viCopy['subtitle'])),
            'notice' => trim((string) ($stored['notice'] ?? $viCopy['notice'])),
        ];
        if ($legacyPage['title'] === '') {
            $legacyPage['title'] = $viCopy['title'];
        }
        $storedLocales = is_array($stored['locales'] ?? null) ? $stored['locales'] : [];
        $localizedPage = [];
        foreach (self::PORTAL_LOCALES as $locale) {
            $copy = $this->portalCopy($locale);
            $localeContent = is_array($storedLocales[$locale] ?? null) ? $storedLocales[$locale] : [];
            $viContent = is_array($storedLocales['vi-VN'] ?? null) ? $storedLocales['vi-VN'] : [];
            foreach (['title', 'subtitle', 'notice'] as $field) {
                $fallback = $legacyPage[$field] === $viCopy[$field]
                    ? $copy[$field]
                    : $legacyPage[$field];
                if (array_key_exists($field, $localeContent)) {
                    $value = trim((string) $localeContent[$field]);
                } elseif ($locale !== 'vi-VN' && array_key_exists($field, $viContent)) {
                    $viValue = trim((string) $viContent[$field]);
                    $value = $viValue === $viCopy[$field] ? $copy[$field] : $viValue;
                } else {
                    $value = $fallback;
                }
                $localizedPage[$locale][$field] = $field === 'title' && $value === '' ? $fallback : $value;
            }
        }

        $hasStoredApps = isset($stored['apps']) && is_array($stored['apps']);
        $storedApps = $hasStoredApps ? $stored['apps'] : $this->defaultEditableApps();
        $apps = collect($storedApps)
            ->filter(fn ($app) => is_array($app))
            ->map(function (array $app, $index) use ($viCopy): array {
                $platform = in_array(($app['platform'] ?? ''), [...self::PORTAL_PLATFORMS, 'other'], true)
                    ? $app['platform']
                    : 'other';
                $viDefaultName = $viCopy['app_names'][$platform] ?? $viCopy['app_default'];
                $viDefaultDescription = $viCopy['app_descriptions'][$platform] ?? '';
                $legacyName = trim((string) ($app['name'] ?? $viDefaultName));
                $legacyDescription = trim((string) ($app['description'] ?? $viDefaultDescription));
                if ($legacyName === '') {
                    $legacyName = $viDefaultName;
                }
                $storedTranslations = is_array($app['translations'] ?? null) ? $app['translations'] : [];
                $translations = [];
                foreach (self::PORTAL_LOCALES as $locale) {
                    $copy = $this->portalCopy($locale);
                    $localeDefaultName = $copy['app_names'][$platform] ?? $copy['app_default'];
                    $localeDefaultDescription = $copy['app_descriptions'][$platform] ?? '';
                    $fallbackName = $legacyName === $viDefaultName ? $localeDefaultName : $legacyName;
                    $fallbackDescription = $legacyDescription === $viDefaultDescription
                        ? $localeDefaultDescription
                        : $legacyDescription;
                    $localeContent = is_array($storedTranslations[$locale] ?? null) ? $storedTranslations[$locale] : [];
                    $viContent = is_array($storedTranslations['vi-VN'] ?? null) ? $storedTranslations['vi-VN'] : [];

                    if (array_key_exists('name', $localeContent)) {
                        $name = trim((string) $localeContent['name']);
                    } elseif ($locale !== 'vi-VN' && array_key_exists('name', $viContent)) {
                        $viName = trim((string) $viContent['name']);
                        $name = $viName === $viDefaultName ? $localeDefaultName : $viName;
                    } else {
                        $name = $fallbackName;
                    }
                    if (array_key_exists('description', $localeContent)) {
                        $description = trim((string) $localeContent['description']);
                    } elseif ($locale !== 'vi-VN' && array_key_exists('description', $viContent)) {
                        $viDescription = trim((string) $viContent['description']);
                        $description = $viDescription === $viDefaultDescription
                            ? $localeDefaultDescription
                            : $viDescription;
                    } else {
                        $description = $fallbackDescription;
                    }
                    $translations[$locale] = [
                        'name' => $name !== '' ? $name : $fallbackName,
                        'description' => $description,
                    ];
                }

                return [
                    'name' => $translations['vi-VN']['name'],
                    'platform' => $platform,
                    'version' => trim((string) ($app['version'] ?? '')),
                    'download_url' => trim((string) ($app['download_url'] ?? '')),
                    'description' => $translations['vi-VN']['description'],
                    'translations' => $translations,
                    'enabled' => (bool) ($app['enabled'] ?? true),
                    'sort' => (int) ($app['sort'] ?? $index),
                ];
            })
            ->values();

        // Upgrade older saved lists once by adding the built-in direct-download
        // catalog. Existing/custom rows are preserved and duplicate URLs are
        // skipped. The version marker prevents removed rows from reappearing.
        $catalogVersion = (int) ($stored['client_catalog_version'] ?? 0);
        if ($catalogVersion < self::CLIENT_CATALOG_VERSION) {
            $existingUrls = $apps->pluck('download_url')->filter()->all();
            foreach ($this->defaultEditableApps() as $defaultApp) {
                $url = $defaultApp['download_url'];
                if ($url !== '' && !in_array($url, $existingUrls, true)) {
                    $apps->push($defaultApp);
                    $existingUrls[] = $url;
                }
            }
            $catalogVersion = self::CLIENT_CATALOG_VERSION;
        }

        return [
            'title' => $localizedPage['vi-VN']['title'],
            'subtitle' => $localizedPage['vi-VN']['subtitle'],
            'notice' => $localizedPage['vi-VN']['notice'],
            'locales' => $localizedPage,
            'support_url' => trim((string) ($stored['support_url']
                ?? (rtrim((string) admin_setting('app_url', 'https://zaoguang-vpn.com'), '/') . '/tickets'))),
            'apps' => $apps->values()->all(),
            'client_catalog_version' => $catalogVersion,
        ];
    }

    private function defaultEditableApps(): array
    {
        $shared = [
            'windows' => ['version' => (string) admin_setting('windows_version', ''), 'download_url' => (string) admin_setting('windows_download_url', ''), 'sort' => 0],
            'macos' => ['version' => (string) admin_setting('macos_version', ''), 'download_url' => (string) admin_setting('macos_download_url', ''), 'sort' => 1],
            'linux' => ['version' => (string) admin_setting('linux_version', ''), 'download_url' => (string) admin_setting('linux_download_url', ''), 'sort' => 2],
            'android' => ['version' => (string) admin_setting('android_version', ''), 'download_url' => (string) admin_setting('android_download_url', ''), 'sort' => 3],
            'ios' => ['version' => (string) admin_setting('ios_version', ''), 'download_url' => (string) admin_setting('ios_download_url', ''), 'sort' => 4],
        ];

        $apps = [];
        $sort = 0;

        // Keep any existing ZaoGuang client links configured in legacy admin
        // settings. They remain available alongside the public client catalog.
        foreach (self::PORTAL_PLATFORMS as $platform) {
            if ($shared[$platform]['download_url'] === '') {
                continue;
            }
            $copy = $this->portalCopy('vi-VN');
            $apps[] = $this->makeCatalogApp(
                $copy['app_names'][$platform],
                $platform,
                $shared[$platform]['version'],
                $shared[$platform]['download_url'],
                $copy['app_descriptions'][$platform],
                $sort++
            );
        }

        // Official release assets. These are binary/package URLs, not vendor
        // landing pages, so the dashboard CTA starts the download directly.
        $catalog = [
            ['Hiddify', 'windows', '4.1.1', 'https://github.com/hiddify/hiddify-app/releases/download/v4.1.1/Hiddify-Windows-Setup-x64.exe'],
            ['Clash Verge Rev', 'windows', '2.5.2', 'https://github.com/clash-verge-rev/clash-verge-rev/releases/download/v2.5.2/Clash.Verge_2.5.2_x64-setup.exe'],
            ['v2rayN', 'windows', '7.24.9', 'https://github.com/2dust/v2rayN/releases/download/7.24.9/v2rayN-windows-64-desktop.zip'],
            ['Hiddify', 'macos', '4.1.1', 'https://github.com/hiddify/hiddify-app/releases/download/v4.1.1/Hiddify-MacOS.dmg'],
            ['Clash Verge Rev', 'macos', '2.5.2', 'https://github.com/clash-verge-rev/clash-verge-rev/releases/download/v2.5.2/Clash.Verge_2.5.2_x64.dmg'],
            ['v2rayN', 'macos', '7.24.9', 'https://github.com/2dust/v2rayN/releases/download/7.24.9/v2rayN-macos-64.dmg'],
            ['Hiddify', 'linux', '4.1.1', 'https://github.com/hiddify/hiddify-app/releases/download/v4.1.1/Hiddify-Linux-x64-AppImage.AppImage'],
            ['Clash Verge Rev', 'linux', '2.5.2', 'https://github.com/clash-verge-rev/clash-verge-rev/releases/download/v2.5.2/Clash.Verge_2.5.2_amd64.deb'],
            ['v2rayN', 'linux', '7.24.9', 'https://github.com/2dust/v2rayN/releases/download/7.24.9/v2rayN-linux-64.zip'],
            ['Hiddify', 'android', '4.1.1', 'https://github.com/hiddify/hiddify-app/releases/download/v4.1.1/Hiddify-Android-universal.apk'],
            ['v2rayNG', 'android', '2.2.6', 'https://github.com/2dust/v2rayNG/releases/download/2.2.6/v2rayNG_2.2.6_arm64-v8a.apk'],
            ['NekoBox', 'android', '1.4.2', 'https://github.com/MatsuriDayo/NekoBoxForAndroid/releases/download/1.4.2/NekoBox-1.4.2-arm64-v8a.apk'],
            ['Shadowsocks', 'windows', '4.4.1.0', 'https://github.com/shadowsocks/shadowsocks-windows/releases/download/4.4.1.0/Shadowsocks-4.4.1.0.zip'],
            ['ShadowsocksX', 'macos', '2.6.3', 'https://github.com/shadowsocks/shadowsocks-iOS/releases/download/2.6.3/ShadowsocksX-2.6.3.dmg'],
            ['Shadowsocks', 'android', '5.3.5-nightly', 'https://github.com/shadowsocks/shadowsocks-android/releases/download/v5.3.5-nightly/shadowsocks-5.3.5-nightly.apk'],
            ['sing-box', 'windows', '1.14.0', 'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFW-1.14.0-x64.exe'],
            ['sing-box', 'macos', '1.14.0', 'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFM-1.14.0-Universal.pkg'],
            ['sing-box', 'linux', '1.14.0', 'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFL-1.14.0-amd64.deb'],
            ['sing-box', 'android', '1.14.0', 'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFA-1.14.0-universal.apk'],
            ['Outline', 'windows', 'stable', 'https://s3.amazonaws.com/outline-releases/client/windows/stable/Outline-Client.exe'],
            ['Outline', 'linux', 'stable', 'https://s3.amazonaws.com/outline-releases/client/linux/stable/outline-client_amd64.deb'],
            ['Outline', 'android', 'stable', 'https://s3.amazonaws.com/outline-releases/client/android/stable/Outline-Client.apk'],
            // Apple requires App Store distribution for signed iOS clients; the
            // link opens the official app listing rather than an untrusted IPA.
            ['Outline (App Store)', 'ios', 'stable', 'https://apps.apple.com/us/app/outline-app/id1356177741'],
        ];

        foreach ($catalog as [$name, $platform, $version, $url]) {
            $copy = $this->portalCopy('vi-VN');
            $apps[] = $this->makeCatalogApp(
                $name,
                $platform,
                $version,
                $url,
                $copy['app_descriptions'][$platform],
                $sort++
            );
        }

        return $apps;
    }

    private function makeCatalogApp(
        string $name,
        string $platform,
        string $version,
        string $downloadUrl,
        string $description,
        int $sort
    ): array {
        $translations = collect(self::PORTAL_LOCALES)->mapWithKeys(function (string $locale) use (
            $name,
            $platform,
            $description
        ): array {
            $copy = $this->portalCopy($locale);

            return [$locale => [
                'name' => $name,
                'description' => $copy['app_descriptions'][$platform] ?? $description,
            ]];
        })->all();

        return [
            'name' => $name,
            'platform' => $platform,
            'version' => $version,
            'download_url' => $downloadUrl,
            'description' => $translations['vi-VN']['description'],
            'translations' => $translations,
            'enabled' => true,
            'sort' => $sort,
        ];
    }

    private function portalLocaleOptions(): array
    {
        return [
            'vi-VN' => 'Tiếng Việt', 'en-US' => 'English', 'zh-CN' => '简体中文', 'zh-TW' => '繁體中文',
            'ja-JP' => '日本語', 'ko-KR' => '한국어', 'fa-IR' => 'فارسی', 'ru-RU' => 'Русский',
        ];
    }

    private function normalizePortalLocale(string $locale): string
    {
        if (in_array($locale, self::PORTAL_LOCALES, true)) {
            return $locale;
        }

        $aliases = [
            'vi' => 'vi-VN', 'en' => 'en-US', 'zh' => 'zh-CN', 'ja' => 'ja-JP',
            'ko' => 'ko-KR', 'fa' => 'fa-IR', 'ru' => 'ru-RU',
        ];
        $language = strtolower(explode('-', $locale)[0] ?? '');

        return $aliases[$language] ?? 'vi-VN';
    }

    private function portalCopy(string $locale): array
    {
        $copy = [
            'vi-VN' => [
                'title' => 'Tải ứng dụng ZaoGuang',
                'subtitle' => 'Chọn đúng ứng dụng cho thiết bị của bạn và tải phiên bản mới nhất.',
                'notice' => 'Chỉ tải ứng dụng từ các liên kết được công bố trên trang này.',
                'app_default' => 'Ứng dụng', 'other' => 'Khác',
                'app_names' => [
                    'windows' => 'ZaoGuang cho Windows', 'macos' => 'ZaoGuang cho macOS',
                    'linux' => 'ZaoGuang cho Linux', 'android' => 'ZaoGuang cho Android', 'ios' => 'ZaoGuang cho iOS',
                ],
                'app_descriptions' => [
                    'windows' => 'Dành cho máy tính Windows 10 và Windows 11.',
                    'macos' => 'Dành cho máy Mac sử dụng Apple Silicon hoặc Intel.',
                    'linux' => 'Dành cho máy tính Linux 64-bit.',
                    'android' => 'Dành cho điện thoại và máy tính bảng Android.',
                    'ios' => 'Dành cho iPhone và iPad.',
                ],
                'back' => 'Về trang khách hàng', 'official' => 'Kho tải chính thức',
                'apps_aria' => 'Danh sách ứng dụng', 'version' => 'Phiên bản', 'download' => 'Tải xuống',
                'empty' => 'Danh sách ứng dụng đang được cập nhật. Vui lòng quay lại sau.',
                'empty_platform' => 'Chưa có bản tải cho :platform. Vui lòng quay lại sau.',
                'footer' => 'Liên kết tải xuống chính thức.', 'support' => 'Cần hỗ trợ?', 'manage' => 'Quản trị kho tải',
            ],
            'en-US' => [
                'title' => 'Download ZaoGuang apps',
                'subtitle' => 'Choose the right app for your device and download the latest version.',
                'notice' => 'Only download apps from links published on this page.',
                'app_default' => 'Application', 'other' => 'Other',
                'app_names' => [
                    'windows' => 'ZaoGuang for Windows', 'macos' => 'ZaoGuang for macOS',
                    'linux' => 'ZaoGuang for Linux', 'android' => 'ZaoGuang for Android', 'ios' => 'ZaoGuang for iOS',
                ],
                'app_descriptions' => [
                    'windows' => 'For Windows 10 and Windows 11 computers.',
                    'macos' => 'For Mac computers with Apple silicon or Intel processors.',
                    'linux' => 'For 64-bit Linux computers.',
                    'android' => 'For Android phones and tablets.',
                    'ios' => 'For iPhone and iPad.',
                ],
                'back' => 'Back to dashboard', 'official' => 'Official download center',
                'apps_aria' => 'Application list', 'version' => 'Version', 'download' => 'Download',
                'empty' => 'The application list is being updated. Please check again later.',
                'empty_platform' => 'No :platform download is available yet. Please check again later.',
                'footer' => 'Official download links.', 'support' => 'Need help?', 'manage' => 'Manage downloads',
            ],
            'zh-CN' => [
                'title' => '下载 ZaoGuang 应用',
                'subtitle' => '请选择适合您设备的应用并下载最新版本。',
                'notice' => '请仅从本页面公布的链接下载应用。',
                'app_default' => '应用', 'other' => '其他',
                'app_names' => [
                    'windows' => 'ZaoGuang Windows 版', 'macos' => 'ZaoGuang macOS 版',
                    'linux' => 'ZaoGuang Linux 版', 'android' => 'ZaoGuang Android 版', 'ios' => 'ZaoGuang iOS 版',
                ],
                'app_descriptions' => [
                    'windows' => '适用于 Windows 10 和 Windows 11 电脑。',
                    'macos' => '适用于搭载 Apple 芯片或 Intel 处理器的 Mac。',
                    'linux' => '适用于 64 位 Linux 电脑。',
                    'android' => '适用于 Android 手机和平板电脑。',
                    'ios' => '适用于 iPhone 和 iPad。',
                ],
                'back' => '返回用户面板', 'official' => '官方下载中心',
                'apps_aria' => '应用列表', 'version' => '版本', 'download' => '下载',
                'empty' => '应用列表正在更新，请稍后再试。',
                'empty_platform' => '暂未提供 :platform 下载，请稍后再试。',
                'footer' => '官方下载链接。', 'support' => '需要帮助？', 'manage' => '管理下载',
            ],
            'zh-TW' => [
                'title' => '下載 ZaoGuang 應用程式',
                'subtitle' => '請選擇適合您裝置的應用程式並下載最新版本。',
                'notice' => '請只從本頁公布的連結下載應用程式。',
                'app_default' => '應用程式', 'other' => '其他',
                'app_names' => [
                    'windows' => 'ZaoGuang Windows 版', 'macos' => 'ZaoGuang macOS 版',
                    'linux' => 'ZaoGuang Linux 版', 'android' => 'ZaoGuang Android 版', 'ios' => 'ZaoGuang iOS 版',
                ],
                'app_descriptions' => [
                    'windows' => '適用於 Windows 10 與 Windows 11 電腦。',
                    'macos' => '適用於搭載 Apple 晶片或 Intel 處理器的 Mac。',
                    'linux' => '適用於 64 位元 Linux 電腦。',
                    'android' => '適用於 Android 手機與平板電腦。',
                    'ios' => '適用於 iPhone 與 iPad。',
                ],
                'back' => '返回使用者面板', 'official' => '官方下載中心',
                'apps_aria' => '應用程式列表', 'version' => '版本', 'download' => '下載',
                'empty' => '應用程式列表正在更新，請稍後再試。',
                'empty_platform' => '目前尚未提供 :platform 下載，請稍後再試。',
                'footer' => '官方下載連結。', 'support' => '需要協助？', 'manage' => '管理下載',
            ],
            'ja-JP' => [
                'title' => 'ZaoGuang アプリをダウンロード',
                'subtitle' => 'お使いの端末に合うアプリを選び、最新バージョンをダウンロードしてください。',
                'notice' => 'アプリはこのページに掲載されたリンクからのみダウンロードしてください。',
                'app_default' => 'アプリ', 'other' => 'その他',
                'app_names' => [
                    'windows' => 'ZaoGuang Windows 版', 'macos' => 'ZaoGuang macOS 版',
                    'linux' => 'ZaoGuang Linux 版', 'android' => 'ZaoGuang Android 版', 'ios' => 'ZaoGuang iOS 版',
                ],
                'app_descriptions' => [
                    'windows' => 'Windows 10 および Windows 11 のパソコン向けです。',
                    'macos' => 'Apple シリコンまたは Intel プロセッサ搭載 Mac 向けです。',
                    'linux' => '64 ビット Linux パソコン向けです。',
                    'android' => 'Android スマートフォンおよびタブレット向けです。',
                    'ios' => 'iPhone および iPad 向けです。',
                ],
                'back' => 'ダッシュボードに戻る', 'official' => '公式ダウンロードセンター',
                'apps_aria' => 'アプリ一覧', 'version' => 'バージョン', 'download' => 'ダウンロード',
                'empty' => 'アプリ一覧を更新しています。しばらくしてからもう一度お試しください。',
                'empty_platform' => ':platform 用のダウンロードはまだありません。しばらくしてからもう一度お試しください。',
                'footer' => '公式ダウンロードリンク。', 'support' => 'サポートが必要ですか？', 'manage' => 'ダウンロード管理',
            ],
            'ko-KR' => [
                'title' => 'ZaoGuang 앱 다운로드',
                'subtitle' => '기기에 맞는 앱을 선택하고 최신 버전을 다운로드하세요.',
                'notice' => '앱은 이 페이지에 게시된 링크에서만 다운로드하세요.',
                'app_default' => '앱', 'other' => '기타',
                'app_names' => [
                    'windows' => 'Windows용 ZaoGuang', 'macos' => 'macOS용 ZaoGuang',
                    'linux' => 'Linux용 ZaoGuang', 'android' => 'Android용 ZaoGuang', 'ios' => 'iOS용 ZaoGuang',
                ],
                'app_descriptions' => [
                    'windows' => 'Windows 10 및 Windows 11 컴퓨터용입니다.',
                    'macos' => 'Apple Silicon 또는 Intel 프로세서를 탑재한 Mac용입니다.',
                    'linux' => '64비트 Linux 컴퓨터용입니다.',
                    'android' => 'Android 휴대전화 및 태블릿용입니다.',
                    'ios' => 'iPhone 및 iPad용입니다.',
                ],
                'back' => '대시보드로 돌아가기', 'official' => '공식 다운로드 센터',
                'apps_aria' => '앱 목록', 'version' => '버전', 'download' => '다운로드',
                'empty' => '앱 목록을 업데이트하고 있습니다. 나중에 다시 확인해 주세요.',
                'empty_platform' => '아직 :platform 다운로드가 없습니다. 나중에 다시 확인해 주세요.',
                'footer' => '공식 다운로드 링크.', 'support' => '도움이 필요하신가요?', 'manage' => '다운로드 관리',
            ],
            'fa-IR' => [
                'title' => 'دانلود برنامه‌های ZaoGuang',
                'subtitle' => 'برنامه مناسب دستگاه خود را انتخاب و جدیدترین نسخه را دانلود کنید.',
                'notice' => 'برنامه‌ها را فقط از پیوندهای منتشرشده در این صفحه دانلود کنید.',
                'app_default' => 'برنامه', 'other' => 'سایر',
                'app_names' => [
                    'windows' => 'ZaoGuang برای Windows', 'macos' => 'ZaoGuang برای macOS',
                    'linux' => 'ZaoGuang برای Linux', 'android' => 'ZaoGuang برای Android', 'ios' => 'ZaoGuang برای iOS',
                ],
                'app_descriptions' => [
                    'windows' => 'برای رایانه‌های Windows 10 و Windows 11.',
                    'macos' => 'برای رایانه‌های Mac با تراشه Apple یا پردازنده Intel.',
                    'linux' => 'برای رایانه‌های ۶۴ بیتی Linux.',
                    'android' => 'برای تلفن‌ها و تبلت‌های Android.',
                    'ios' => 'برای iPhone و iPad.',
                ],
                'back' => 'بازگشت به پیشخوان', 'official' => 'مرکز رسمی دانلود',
                'apps_aria' => 'فهرست برنامه‌ها', 'version' => 'نسخه', 'download' => 'دانلود',
                'empty' => 'فهرست برنامه‌ها در حال به‌روزرسانی است. بعداً دوباره بررسی کنید.',
                'empty_platform' => 'هنوز دانلودی برای :platform موجود نیست. بعداً دوباره بررسی کنید.',
                'footer' => 'پیوندهای رسمی دانلود.', 'support' => 'به کمک نیاز دارید؟', 'manage' => 'مدیریت دانلودها',
            ],
            'ru-RU' => [
                'title' => 'Скачать приложения ZaoGuang',
                'subtitle' => 'Выберите приложение для своего устройства и скачайте последнюю версию.',
                'notice' => 'Скачивайте приложения только по ссылкам, опубликованным на этой странице.',
                'app_default' => 'Приложение', 'other' => 'Другое',
                'app_names' => [
                    'windows' => 'ZaoGuang для Windows', 'macos' => 'ZaoGuang для macOS',
                    'linux' => 'ZaoGuang для Linux', 'android' => 'ZaoGuang для Android', 'ios' => 'ZaoGuang для iOS',
                ],
                'app_descriptions' => [
                    'windows' => 'Для компьютеров с Windows 10 и Windows 11.',
                    'macos' => 'Для компьютеров Mac с процессором Apple или Intel.',
                    'linux' => 'Для 64-разрядных компьютеров с Linux.',
                    'android' => 'Для телефонов и планшетов Android.',
                    'ios' => 'Для iPhone и iPad.',
                ],
                'back' => 'Вернуться в панель', 'official' => 'Официальный центр загрузок',
                'apps_aria' => 'Список приложений', 'version' => 'Версия', 'download' => 'Скачать',
                'empty' => 'Список приложений обновляется. Повторите попытку позже.',
                'empty_platform' => 'Загрузка для :platform пока недоступна. Повторите попытку позже.',
                'footer' => 'Официальные ссылки для загрузки.', 'support' => 'Нужна помощь?', 'manage' => 'Управление загрузками',
            ],
        ];

        $filterCopy = [
            'vi-VN' => [
                'search' => 'Tìm kiếm', 'search_placeholder' => 'Tìm ứng dụng hoặc hệ điều hành…',
                'platform_filter' => 'Hệ điều hành', 'all_platforms' => 'Tất cả hệ điều hành',
                'sort' => 'Sắp xếp', 'sort_default' => 'Mặc định', 'sort_name' => 'Tên A–Z',
                'sort_platform' => 'Theo hệ điều hành', 'sort_version' => 'Phiên bản mới', 'apply' => 'Áp dụng',
                'search_empty' => 'Không tìm thấy ứng dụng phù hợp với từ khóa “:query”.',
            ],
            'en-US' => [
                'search' => 'Search', 'search_placeholder' => 'Search apps or operating systems…',
                'platform_filter' => 'Operating system', 'all_platforms' => 'All operating systems',
                'sort' => 'Sort by', 'sort_default' => 'Default', 'sort_name' => 'Name A–Z',
                'sort_platform' => 'Operating system', 'sort_version' => 'Newest version', 'apply' => 'Apply',
                'search_empty' => 'No apps match “:query”.',
            ],
            'zh-CN' => [
                'search' => '搜索', 'search_placeholder' => '搜索应用或操作系统…',
                'platform_filter' => '操作系统', 'all_platforms' => '所有操作系统',
                'sort' => '排序', 'sort_default' => '默认', 'sort_name' => '名称 A–Z',
                'sort_platform' => '按操作系统', 'sort_version' => '最新版本', 'apply' => '应用',
                'search_empty' => '没有符合“:query”的应用。',
            ],
            'zh-TW' => [
                'search' => '搜尋', 'search_placeholder' => '搜尋應用程式或作業系統…',
                'platform_filter' => '作業系統', 'all_platforms' => '所有作業系統',
                'sort' => '排序', 'sort_default' => '預設', 'sort_name' => '名稱 A–Z',
                'sort_platform' => '依作業系統', 'sort_version' => '最新版本', 'apply' => '套用',
                'search_empty' => '找不到符合「:query」的應用程式。',
            ],
            'ja-JP' => [
                'search' => '検索', 'search_placeholder' => 'アプリや OS を検索…',
                'platform_filter' => 'OS', 'all_platforms' => 'すべての OS',
                'sort' => '並べ替え', 'sort_default' => '既定', 'sort_name' => '名前 A–Z',
                'sort_platform' => 'OS 順', 'sort_version' => '新しいバージョン', 'apply' => '適用',
                'search_empty' => '「:query」に一致するアプリはありません。',
            ],
            'ko-KR' => [
                'search' => '검색', 'search_placeholder' => '앱 또는 운영체제 검색…',
                'platform_filter' => '운영체제', 'all_platforms' => '모든 운영체제',
                'sort' => '정렬', 'sort_default' => '기본', 'sort_name' => '이름 A–Z',
                'sort_platform' => '운영체제순', 'sort_version' => '최신 버전', 'apply' => '적용',
                'search_empty' => '“:query”와 일치하는 앱이 없습니다.',
            ],
            'fa-IR' => [
                'search' => 'جست‌وجو', 'search_placeholder' => 'جست‌وجوی برنامه یا سیستم‌عامل…',
                'platform_filter' => 'سیستم‌عامل', 'all_platforms' => 'همه سیستم‌عامل‌ها',
                'sort' => 'مرتب‌سازی', 'sort_default' => 'پیش‌فرض', 'sort_name' => 'نام A–Z',
                'sort_platform' => 'بر اساس سیستم‌عامل', 'sort_version' => 'جدیدترین نسخه', 'apply' => 'اعمال',
                'search_empty' => 'برنامه‌ای مطابق «:query» پیدا نشد.',
            ],
            'ru-RU' => [
                'search' => 'Поиск', 'search_placeholder' => 'Поиск приложений или ОС…',
                'platform_filter' => 'Операционная система', 'all_platforms' => 'Все операционные системы',
                'sort' => 'Сортировка', 'sort_default' => 'По умолчанию', 'sort_name' => 'Название A–Z',
                'sort_platform' => 'По ОС', 'sort_version' => 'Новая версия', 'apply' => 'Применить',
                'search_empty' => 'Приложений по запросу «:query» не найдено.',
            ],
        ];
        foreach ($filterCopy as $copyLocale => $labels) {
            $copy[$copyLocale] = array_merge($labels, $copy[$copyLocale] ?? []);
        }

        return $copy[$locale] ?? $copy['vi-VN'];
    }
}
