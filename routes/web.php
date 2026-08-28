<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Models\Order;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


$renderTheme = function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        $requestHost = $request->getHost();
        $configHost = parse_url(admin_setting('app_url'), PHP_URL_HOST);
        
        if ($requestHost !== $configHost) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        $themePath = $themeService->getThemePath($theme);
        // A custom runtime file may create the public theme directory before
        // the first request. Treat a missing or partial assets directory as
        // uninitialized so route chunks (profile, orders, mobile navigation,
        // etc.) are published instead of falling through to the HTML shell.
        $publicThemeAssets = $publicThemePath . '/assets';
        $sourceThemeAssets = $themePath ? $themePath . '/assets' : null;
        $needsThemePublish = !File::exists($publicThemePath)
            || ($sourceThemeAssets && File::isDirectory($sourceThemeAssets) && !File::isDirectory($publicThemeAssets));
        if (!$needsThemePublish && $sourceThemeAssets && File::isDirectory($sourceThemeAssets)) {
            // Compare relative asset paths, not just directory existence. A
            // previous deployment could have copied only the entry chunk,
            // leaving lazy-loaded pages (and the mobile nav component) absent.
            $publicAssetPaths = [];
            foreach (File::allFiles($publicThemeAssets) as $publicAsset) {
                $relative = ltrim(str_replace($publicThemeAssets, '', $publicAsset->getPathname()), DIRECTORY_SEPARATOR . '/');
                $publicAssetPaths[$relative] = true;
            }
            foreach (File::allFiles($sourceThemeAssets) as $sourceAsset) {
                $relative = ltrim(str_replace($sourceThemeAssets, '', $sourceAsset->getPathname()), DIRECTORY_SEPARATOR . '/');
                if (!isset($publicAssetPaths[$relative])) {
                    $needsThemePublish = true;
                    break;
                }
            }
        }
        if ($needsThemePublish) {
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }
        // Runtime overrides (i18n and the customized shell) live in the
        // mounted theme directory. Keep their public copies fresh even when
        // the rest of the theme assets were already published.
        if ($themePath) {
            foreach ([
                'i18n-v18.js',
                'dashboard.blade.php',
                'assets/luck-overrides.css',
                'assets/oPGsis9D-v3.js',
                'assets/oPGsis9D-v2.js',
                'assets/BBbuoBq5.js',
                'assets/C0KnXkt1-v2.js',
                'assets/lsrL0SOU-v2.js',
                'assets/C6e3mGRa-v4.js',
                'assets/BBbuoBq5-v8.js',
                // Cache-busted Luck chunks used by the repaired mobile shell.
                // They are copied on demand so a persistent theme volume is
                // refreshed after an image update without a manual step.
                'assets/DM1yaN1X-v2.js',
                'assets/DM1yaN1X-v3.js',
                'assets/BEq_qS6Y-v2.js',
                'assets/3u1s8V6K-v2.js',
                'assets/CO5Ntz5l-v3.js',
                'assets/C6e3mGRa-v6.js',
                'assets/oPGsis9D-v7.js',
                'assets/BBbuoBq5-v12.js',
            ] as $runtimeFile) {
                $source = $themePath . '/' . $runtimeFile;
                $target = $publicThemePath . '/' . $runtimeFile;
                if (File::exists($source)) {
                    try {
                        File::ensureDirectoryExists(dirname($target));
                        // Luck's generated world-map chunk has occasionally
                        // shipped with one extra closing brace around the
                        // country aggregation callback.  A browser then
                        // rejects the lazy chunk and the Nodes route stays
                        // blank (especially visible on mobile).  Repair only
                        // that exact generated fragment before publishing;
                        // all other assets continue through a byte-for-byte
                        // copy.
                        // The stock entry references the world-map chunk without
                        // a query string. Give that one import a fresh URL so a
                        // browser cannot reuse a previously cached malformed
                        // map chunk after it has been repaired below.
                        if ($runtimeFile === 'assets/BBbuoBq5.js') {
                            $assetContents = @file_get_contents($source);
                            $fixedContents = $assetContents === false ? false : str_replace(
                                [
                                    './oPGsis9D-v2.js?v=50',
                                    './oPGsis9D-v2.js?v=51',
                                    './oPGsis9D-v2.js?v=53',
                                    './oPGsis9D-v2.js',
                                ],
                                [
                                    './oPGsis9D-v2-fresh.js',
                                    './oPGsis9D-v2-fresh.js',
                                    './oPGsis9D-v2-fresh.js',
                                    './oPGsis9D-v2-fresh.js',
                                ],
                                $assetContents
                            );
                            if ($fixedContents !== false) {
                                $fixedContents = str_replace(
                                    [
                                        'assets/DM1yaN1X.js',
                                        'assets/DM1yaN1X-v3.js?v=50',
                                        'assets/DM1yaN1X-v3.js',
                                        'assets/BEq_qS6Y.js',
                                        'assets/BEq_qS6Y-v3.js',
                                        'assets/oPGsis9D-v2.js',
                                        'assets/oPGsis9D-v2.js?v=50',
                                        'assets/oPGsis9D-v2.js?v=53',
                                    ],
                                    [
                                        'assets/DM1yaN1X-fresh.js',
                                        'assets/DM1yaN1X-fresh.js',
                                        'assets/DM1yaN1X-fresh.js',
                                        'assets/BEq_qS6Y-fresh.js',
                                        'assets/BEq_qS6Y-fresh.js',
                                        'assets/oPGsis9D-v2-fresh.js',
                                        'assets/oPGsis9D-v2-fresh.js',
                                        'assets/oPGsis9D-v2-fresh.js',
                                    ],
                                    $fixedContents
                                );
                            }
                            if ($fixedContents !== false) {
                                // The map route imports Vue's runtime by a
                                // bare URL. Bust that dependency too, since a
                                // previous broken response may still be in a
                                // mobile browser cache for several hours.
                                $fixedContents = str_replace(
                                    [
                                        './DM1yaN1X.js',
                                        './DM1yaN1X-v3.js',
                                        './DM1yaN1X-v3.js?v=50',
                                        './DM1yaN1X-v3.js?v=53',
                                        './DM1yaN1X-v2.js',
                                    ],
                                    [
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                    ],
                                    $fixedContents
                                );
                                if (@file_put_contents($target, $fixedContents) === false) {
                                    Log::warning('Theme entry asset could not be cache-busted', ['target' => $target]);
                                }
                                continue;
                            }
                        }
                        if (in_array($runtimeFile, ['assets/oPGsis9D-v2.js', 'assets/oPGsis9D-v3.js'], true)) {
                            $assetContents = @file_get_contents($source);
                            $fixedContents = $assetContents === false ? false : str_replace(
                                "\n            }\n          }\n        }\n      });\n      return countryMap;",
                                "\n            }\n          }\n      });\n      return countryMap;",
                                $assetContents
                            );
                            if ($fixedContents !== false) {
                                $fixedContents = str_replace(
                                    [
                                        './DM1yaN1X.js?v=50',
                                        './DM1yaN1X.js',
                                        './DM1yaN1X-v3.js?v=50',
                                        './DM1yaN1X-v3.js',
                                        './DM1yaN1X-v2.js',
                                    ],
                                    [
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                        './DM1yaN1X-fresh.js',
                                    ],
                                    $fixedContents
                                );
                                if (@file_put_contents($target, $fixedContents) === false) {
                                    Log::warning('Theme world-map asset could not be repaired', ['target' => $target]);
                                }
                                continue;
                            }
                        }
                        // A read-only/publicly prebuilt theme must not turn a
                        // normal page request into a 500 just because an
                        // optional runtime override cannot be copied.
                        if (!@copy($source, $target)) {
                            Log::warning('Theme runtime override could not be copied', ['target' => $target]);
                        }
                    } catch (\Throwable $copyError) {
                        Log::warning('Theme runtime override failed', ['target' => $target, 'error' => $copyError->getMessage()]);
                    }
                }
            }
        }

        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeService->getConfig($theme)
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
};

Route::get('/', $renderTheme);

// Dedicated VietQR payment page. The checkout endpoint returns this URL for
// SePay orders so customers can pay on the banking subdomain.
Route::get('/pay/{tradeNo}', function (Request $request, string $tradeNo) {
    $order = Order::with(['payment', 'plan'])
        ->where('trade_no', $tradeNo)
        ->first();
    if (!$order || !$order->payment || strtolower((string) $order->payment->payment) !== 'sepay') {
        abort(404);
    }

    $config = is_array($order->payment->config) ? $order->payment->config : [];
    $totalAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
    $rate = (float) ($config['sepay_cny_vnd_rate'] ?? 0);
    $amountVnd = (int) round(($totalAmount / 100) * $rate);
    $expiresAt = (int) $order->created_at + (2 * 60 * 60);

    if (!class_exists(\Plugin\Sepay\Plugin::class)) {
        abort(503, 'Payment gateway is unavailable');
    }
    $plugin = new \Plugin\Sepay\Plugin('sepay');
    $plugin->setConfig($config);
    $qrUrl = $plugin->qrUrl([
        'trade_no' => $order->trade_no,
        'total_amount' => $totalAmount,
    ]);

    $languages = collect($request->getLanguages())
        ->map(fn ($language) => strtolower(str_replace('_', '-', (string) $language)))
        ->all();
    $locale = 'en-US';
    foreach ($languages as $language) {
        if (str_starts_with($language, 'vi')) { $locale = 'vi-VN'; break; }
        if (str_starts_with($language, 'zh')) { $locale = 'zh-CN'; break; }
        if (str_starts_with($language, 'ja')) { $locale = 'ja-JP'; break; }
        if (str_starts_with($language, 'ko')) { $locale = 'ko-KR'; break; }
    }
    if ($request->filled('lang')) {
        $requested = str_replace('_', '-', (string) $request->input('lang'));
        if (in_array($requested, ['vi-VN', 'en-US', 'zh-CN', 'ja-JP', 'ko-KR'], true)) {
            $locale = $requested;
        }
    }

    $panelUrl = rtrim((string) admin_setting('app_url', 'https://zaoguang-vpn.com'), '/');
    return view('payment.banking', [
        'order' => $order,
        'qrUrl' => $qrUrl,
        'expiresAt' => $expiresAt,
        'locale' => $locale,
        'amountVnd' => $amountVnd,
        'amountCny' => number_format($totalAmount / 100, 2),
        'paymentAccount' => $plugin->paymentAccountNumber(),
        'accountName' => (string) ($config['sepay_account_name'] ?? ''),
        'bankName' => (string) ($config['sepay_bank_code'] ?? ''),
        'transferDescription' => trim((string) ($config['sepay_transfer_prefix'] ?? 'XBOARD')) . ' ' . $order->trade_no,
        'statusUrl' => url('/payment/status/' . rawurlencode($order->trade_no)),
        'returnUrl' => $panelUrl . '/orders?trade_no=' . rawurlencode($order->trade_no),
    ]);
})->where('tradeNo', '[A-Za-z0-9_-]+');

Route::get('/payment/status/{tradeNo}', function (string $tradeNo) {
    $order = Order::where('trade_no', $tradeNo)->first();
    if (!$order) {
        return response()->json(['message' => 'Order not found'], 404);
    }
    return response()->json([
        'status' => (int) $order->status,
        'expires_at' => (int) $order->created_at + (2 * 60 * 60),
    ]);
})->where('tradeNo', '[A-Za-z0-9_-]+');

// The Luck theme is a history-mode SPA. Serve its shell for client-side
// routes as well, so refreshing /servers, /profile, /orders, etc. does not
// fall through to Laravel's 404 page before Vue Router can boot.
Route::get('/{path}', $renderTheme)->where('path', 'login|register|dashboard|plans|plans/purchase/[^/]+|servers|orders|tickets|traffic-details|invite|profile|docs');

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');
