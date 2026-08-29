<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ResourcePortalController extends Controller
{
    public function index()
    {
        $config = $this->getPortalConfig();
        $apps = collect($config['apps'])
            ->filter(fn (array $app) => $app['enabled'] && $app['download_url'] !== '')
            ->sortBy('sort')
            ->values()
            ->all();

        return view('resources.portal', [
            'config' => $config,
            'apps' => $apps,
            'appName' => admin_setting('app_name', 'ZaoGuang Service'),
            'logo' => admin_setting('logo'),
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
        ]);
    }

    public function fetch()
    {
        return $this->success($this->getPortalConfig());
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:500',
            'notice' => 'nullable|string|max:1000',
            'support_url' => 'nullable|url|max:2048',
            'apps' => 'present|array|max:20',
            'apps.*.name' => 'required|string|max:100',
            'apps.*.platform' => 'required|in:windows,android,macos,ios,linux,other',
            'apps.*.version' => 'nullable|string|max:50',
            'apps.*.download_url' => 'nullable|url|max:2048',
            'apps.*.description' => 'nullable|string|max:300',
            'apps.*.enabled' => 'required|boolean',
            'apps.*.sort' => 'required|integer|min:0|max:999',
        ]);

        $validated['subtitle'] = trim((string) ($validated['subtitle'] ?? ''));
        $validated['notice'] = trim((string) ($validated['notice'] ?? ''));
        $validated['support_url'] = trim((string) ($validated['support_url'] ?? ''));
        $validated['apps'] = collect($validated['apps'])
            ->map(fn (array $app) => [
                'name' => trim($app['name']),
                'platform' => $app['platform'],
                'version' => trim((string) ($app['version'] ?? '')),
                'download_url' => trim((string) ($app['download_url'] ?? '')),
                'description' => trim((string) ($app['description'] ?? '')),
                'enabled' => (bool) $app['enabled'],
                'sort' => (int) $app['sort'],
            ])
            ->values()
            ->all();

        admin_setting(['resource_portal' => $validated]);

        return $this->success($validated);
    }

    private function getPortalConfig(): array
    {
        $stored = admin_setting('resource_portal', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $defaults = [
            'title' => 'Tải ứng dụng ZaoGuang',
            'subtitle' => 'Chọn đúng ứng dụng cho thiết bị của bạn và tải phiên bản mới nhất.',
            'notice' => 'Chỉ tải ứng dụng từ các liên kết được công bố trên trang này.',
            'support_url' => rtrim((string) admin_setting('app_url', 'https://zaoguang-vpn.com'), '/') . '/tickets',
            'apps' => $this->legacyApps(),
        ];

        $config = array_merge($defaults, array_intersect_key($stored, $defaults));
        $config['apps'] = isset($stored['apps']) && is_array($stored['apps'])
            ? $stored['apps']
            : $defaults['apps'];

        $config['apps'] = collect($config['apps'])
            ->filter(fn ($app) => is_array($app))
            ->map(fn (array $app, $index) => [
                'name' => trim((string) ($app['name'] ?? 'Ứng dụng')),
                'platform' => in_array(($app['platform'] ?? ''), ['windows', 'android', 'macos', 'ios', 'linux', 'other'], true)
                    ? $app['platform']
                    : 'other',
                'version' => trim((string) ($app['version'] ?? '')),
                'download_url' => trim((string) ($app['download_url'] ?? '')),
                'description' => trim((string) ($app['description'] ?? '')),
                'enabled' => (bool) ($app['enabled'] ?? true),
                'sort' => (int) ($app['sort'] ?? $index),
            ])
            ->values()
            ->all();

        return $config;
    }

    private function legacyApps(): array
    {
        return [
            [
                'name' => 'ZaoGuang cho Windows',
                'platform' => 'windows',
                'version' => (string) admin_setting('windows_version', ''),
                'download_url' => (string) admin_setting('windows_download_url', ''),
                'description' => 'Dành cho máy tính Windows 10 và Windows 11.',
                'enabled' => true,
                'sort' => 0,
            ],
            [
                'name' => 'ZaoGuang cho Android',
                'platform' => 'android',
                'version' => (string) admin_setting('android_version', ''),
                'download_url' => (string) admin_setting('android_download_url', ''),
                'description' => 'Dành cho điện thoại và máy tính bảng Android.',
                'enabled' => true,
                'sort' => 1,
            ],
            [
                'name' => 'ZaoGuang cho macOS',
                'platform' => 'macos',
                'version' => (string) admin_setting('macos_version', ''),
                'download_url' => (string) admin_setting('macos_download_url', ''),
                'description' => 'Dành cho máy Mac sử dụng Apple Silicon hoặc Intel.',
                'enabled' => true,
                'sort' => 2,
            ],
        ];
    }
}
