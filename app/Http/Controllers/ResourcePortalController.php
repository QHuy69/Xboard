<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ResourcePortalController extends Controller
{
    private const PORTAL_LOCALES = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

    private const PORTAL_PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios'];

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
        $apps = collect($config['apps'])
            ->filter(fn (array $app) => $app['enabled'] && $app['download_url'] !== '')
            ->sortBy('sort')
            ->when($selectedPlatform !== '', fn ($items) => $items->where('platform', $selectedPlatform))
            ->values()
            ->all();

        return view('resources.portal', [
            'config' => $config,
            'apps' => $apps,
            'appName' => admin_setting('app_name', 'ZaoGuang Service'),
            'logo' => admin_setting('logo'),
            'selectedPlatform' => $selectedPlatform,
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

    public function save(Request $request)
    {
        $rules = [
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:500',
            'notice' => 'nullable|string|max:1000',
            'locales' => 'nullable|array',
            'support_url' => 'nullable|url|max:2048',
            'apps' => 'present|array|max:20',
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
            'apps' => collect($editable['apps'])->map(function (array $app) use ($locale): array {
                $translation = $app['translations'][$locale]
                    ?? $app['translations']['vi-VN']
                    ?? ['name' => $app['name'], 'description' => $app['description']];

                return [
                    'name' => $translation['name'],
                    'platform' => $app['platform'],
                    'version' => $app['version'],
                    'download_url' => $app['download_url'],
                    'description' => $translation['description'],
                    'enabled' => $app['enabled'],
                    'sort' => $app['sort'],
                ];
            })->all(),
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

        $storedApps = isset($stored['apps']) && is_array($stored['apps'])
            ? $stored['apps']
            : $this->defaultEditableApps();
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

        // Old saved lists may predate Linux/iOS. Preserve every old/custom row
        // and only add an OS whose platform is genuinely absent.
        $existingPlatforms = $apps->pluck('platform')->unique()->all();
        foreach ($this->defaultEditableApps() as $defaultApp) {
            if (!in_array($defaultApp['platform'], $existingPlatforms, true)) {
                $apps->push($defaultApp);
                $existingPlatforms[] = $defaultApp['platform'];
            }
        }

        return [
            'title' => $localizedPage['vi-VN']['title'],
            'subtitle' => $localizedPage['vi-VN']['subtitle'],
            'notice' => $localizedPage['vi-VN']['notice'],
            'locales' => $localizedPage,
            'support_url' => trim((string) ($stored['support_url']
                ?? (rtrim((string) admin_setting('app_url', 'https://zaoguang-vpn.com'), '/') . '/tickets'))),
            'apps' => $apps->values()->all(),
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

        return collect(self::PORTAL_PLATFORMS)->map(function (string $platform) use ($shared): array {
            $translations = collect(self::PORTAL_LOCALES)->mapWithKeys(function (string $locale) use ($platform): array {
                $copy = $this->portalCopy($locale);

                return [$locale => [
                    'name' => $copy['app_names'][$platform],
                    'description' => $copy['app_descriptions'][$platform],
                ]];
            })->all();

            return [
                'name' => $translations['vi-VN']['name'],
                'platform' => $platform,
                'version' => $shared[$platform]['version'],
                'download_url' => $shared[$platform]['download_url'],
                'description' => $translations['vi-VN']['description'],
                'translations' => $translations,
                'enabled' => true,
                'sort' => $shared[$platform]['sort'],
            ];
        })->all();
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

        return $copy[$locale] ?? $copy['vi-VN'];
    }
}
