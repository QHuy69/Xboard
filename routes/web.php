<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use App\Services\LuckThemeAssetPatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Models\Order;
use App\Http\Controllers\ResourcePortalController;
use App\Http\Controllers\UsdtDirectCheckoutController;

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
            $runtimeFiles = [
                'i18n-v18.js',
                'dashboard.blade.php',
                'assets/luck-overrides.css',
                'assets/luck-clash.svg',
                'assets/oPGsis9D-v3.js',
                'assets/oPGsis9D-v2.js',
                'assets/oPGsis9D-v3-fresh.js',
                'assets/oPGsis9D-v2-fresh.js',
                'assets/BBbuoBq5.js',
                'assets/BBbuoBq5-fresh.js',
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
            ];

            // Luck changes the generated node chunk filename between theme
            // releases (v2, v3, v8, etc.). Discover every variant instead of
            // tying the access-link repair to one compiled filename.
            foreach (File::glob($themePath . '/assets/oPGsis9D*.js') ?: [] as $nodeAsset) {
                $relative = 'assets/' . basename($nodeAsset);
                if (!in_array($relative, $runtimeFiles, true)) {
                    $runtimeFiles[] = $relative;
                }
            }
            // The main Luck entry filename is versioned independently from
            // the node chunk. Patch every generated entry so a theme update
            // cannot silently switch Copy/QR back to the fake ss://host:port
            // implementation.
            foreach (File::glob($themePath . '/assets/BBbuoBq5*.js') ?: [] as $entryAsset) {
                $relative = 'assets/' . basename($entryAsset);
                if (!in_array($relative, $runtimeFiles, true)) {
                    $runtimeFiles[] = $relative;
                }
            }
            // Patch every compiled JavaScript chunk, including route chunks
            // whose hashes and version suffixes change between Luck releases.
            // This is deliberately broader than the feature-specific list:
            // animation labels can live in any lazy-loaded page.
            foreach (LuckThemeAssetPatcher::discoverJavascriptAssets($themePath) as $relative) {
                if (!in_array($relative, $runtimeFiles, true)) {
                    $runtimeFiles[] = $relative;
                }
            }

            foreach ($runtimeFiles as $runtimeFile) {
                $source = $themePath . '/' . $runtimeFile;
                $target = $publicThemePath . '/' . $runtimeFile;
                if (File::exists($source)) {
                    try {
                        File::ensureDirectoryExists(dirname($target));
                        $loadingPatchedContents = false;
                        if (str_ends_with($runtimeFile, '.js')) {
                            $javascriptContents = @file_get_contents($source);
                            if ($javascriptContents !== false) {
                                // Patch VNode loading labels before any
                                // feature-specific rewrite and before the
                                // chunk is published to public/theme.
                                $loadingPatchedContents = LuckThemeAssetPatcher::patchLoadingAnimations($javascriptContents);
                                $loadingPatchedContents = LuckThemeAssetPatcher::patchPortableUnicodeIcons($loadingPatchedContents);
                                $loadingPatchedContents = LuckThemeAssetPatcher::patchClashIcon($loadingPatchedContents);
                                $loadingPatchedContents = LuckThemeAssetPatcher::patchDashboardLogoFallback($loadingPatchedContents);
                                // Every lazy chunk must select the same shared
                                // runtime. An unchanged nested import can revive
                                // a browser-cached pre-v6 dialog after the
                                // entry has already selected payment-v6.
                                $loadingPatchedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($loadingPatchedContents);
                                $loadingPatchedContents = LuckThemeAssetPatcher::rewriteSubscriptionDialogAssetImport($loadingPatchedContents);
                            }
                        }
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
                        if (str_starts_with($runtimeFile, 'assets/BBbuoBq5')
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            $fixedContents = $assetContents === false
                                ? false
                                : LuckThemeAssetPatcher::rewriteNodeAssetImport($assetContents);
                            if ($fixedContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'BR9H_Zte', '-localized');
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'CK-I2Xx_', '-free');
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'DSCv3-VU', '-managed');
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'BBIEjj8f', '-auth-v3');
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'q_WC3BFv', '-register-v2');
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'ByaxWMaA', '-localized');
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'C0KnXkt1', '-payment-v4');
                                $fixedContents = LuckThemeAssetPatcher::rewriteSubscriptionDialogAssetImport($fixedContents);
                                $fixedContents = LuckThemeAssetPatcher::versionPortableIconAssetImports($fixedContents);
                                $fixedContents = LuckThemeAssetPatcher::patchSharedAuth($fixedContents);
                                $fixedContents = str_replace(
                                    [
                                        'assets/DM1yaN1X.js',
                                        'assets/DM1yaN1X-v3.js?v=50',
                                        'assets/DM1yaN1X-v3.js',
                                        'assets/BEq_qS6Y.js',
                                        'assets/BEq_qS6Y-v3.js',
                                    ],
                                    [
                                        'assets/DM1yaN1X-fresh.js',
                                        'assets/DM1yaN1X-fresh.js',
                                        'assets/DM1yaN1X-fresh.js',
                                        'assets/BEq_qS6Y-fresh.js',
                                        'assets/BEq_qS6Y-fresh.js',
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
                                $fixedContents = LuckThemeAssetPatcher::versionDashboardRouteAssetImport($fixedContents);
                                if (@file_put_contents($target, $fixedContents) === false) {
                                    Log::warning('Theme entry asset could not be cache-busted', ['target' => $target]);
                                }
                                // Lazy route chunks import the generated entry again as their
                                // shared store/API module. Publish it under a new URL as well;
                                // otherwise a browser can execute a cached nested entry which
                                // still points back to the unpatched login and plan chunks.
                                $runtimeTarget = $publicThemePath . '/assets/'
                                    . LuckThemeAssetPatcher::sharedRuntimeAssetName($runtimeFile);
                                if (@file_put_contents($runtimeTarget, $fixedContents) === false) {
                                    Log::warning('Theme shared runtime asset could not be cache-busted', ['target' => $runtimeTarget]);
                                }
                                continue;
                            }
                        }
                        if (str_starts_with($runtimeFile, 'assets/oPGsis9D')
                            && str_ends_with($runtimeFile, '.js')
                            && !preg_match('/(?:-access(?:-v\d+)?)+\.js$/', basename($runtimeFile))) {
                            $assetContents = $loadingPatchedContents;
                            $fixedContents = $assetContents === false ? false : str_replace(
                                "\n            }\n          }\n        }\n      });\n      return countryMap;",
                                "\n            }\n          }\n      });\n      return countryMap;",
                                $assetContents
                            );
                            if ($fixedContents !== false) {
                                // The stock node dialog manufactures demo URLs
                                // (and for Shadowsocks only ss://host:port).
                                // Prefer the real per-user URL generated by the
                                // backend; both Copy and QR call this function.
                                if (!str_contains($fixedContents, 'server.access_url')) {
                                    $fixedContents = str_replace(
                                        'const generateSubscriptionLink = (server) => {',
                                        "const generateSubscriptionLink = (server) => {\n      if (typeof server.access_url === \"string\" && server.access_url.length > 0) {\n        return server.access_url;\n      }",
                                        $fixedContents
                                    );
                                }
                                $fixedContents = LuckThemeAssetPatcher::patchNodeFlags($fixedContents);
                                $fixedContents = LuckThemeAssetPatcher::patchNodeScrollbar($fixedContents);
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
                                $fixedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($fixedContents);
                                if (@file_put_contents($target, $fixedContents) === false) {
                                    Log::warning('Theme world-map asset could not be repaired', ['target' => $target]);
                                }
                                $accessName = LuckThemeAssetPatcher::nodeAccessAssetName($runtimeFile);
                                $accessTarget = $publicThemePath . '/assets/' . $accessName;
                                if (@file_put_contents($accessTarget, $fixedContents) === false) {
                                    Log::warning('Theme node access asset could not be published', ['target' => $accessTarget]);
                                }
                                continue;
                            }
                        }
                        if (str_starts_with($runtimeFile, 'assets/BR9H_Zte')
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            if ($assetContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::patchTrafficChart($assetContents);
                                $fixedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($fixedContents);
                                @file_put_contents($target, $fixedContents);
                                $localizedTarget = preg_replace('/\.js$/', '-localized.js', $target);
                                @file_put_contents($localizedTarget, $fixedContents);
                                continue;
                            }
                        }
                        if (str_starts_with($runtimeFile, 'assets/CK-I2Xx_')
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            if ($assetContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::patchFreePlans($assetContents);
                                $fixedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($fixedContents);
                                @file_put_contents($target, $fixedContents);
                                $freeTarget = preg_replace('/\.js$/', '-free.js', $target);
                                @file_put_contents($freeTarget, $fixedContents);
                                continue;
                            }
                        }
                        if (str_starts_with($runtimeFile, 'assets/DSCv3-VU')
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            if ($assetContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::patchInviteManagement($assetContents);
                                $fixedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($fixedContents);
                                @file_put_contents($target, $fixedContents);
                                $managedTarget = preg_replace('/\.js$/', '-managed.js', $target);
                                @file_put_contents($managedTarget, $fixedContents);
                                continue;
                            }
                        }
                        if (str_starts_with($runtimeFile, 'assets/BBIEjj8f')
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            if ($assetContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::patchLoginErrors($assetContents);
                                $fixedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($fixedContents);
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'ByaxWMaA', '-localized');
                                @file_put_contents($target, $fixedContents);
                                $errorsTarget = preg_replace('/\.js$/', '-auth-v3.js', $target);
                                @file_put_contents($errorsTarget, $fixedContents);
                                continue;
                            }
                        }
                        if (str_starts_with($runtimeFile, 'assets/q_WC3BFv')
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            if ($assetContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::patchRegisterFlow($assetContents);
                                $fixedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($fixedContents);
                                $fixedContents = LuckThemeAssetPatcher::rewriteAssetImport($fixedContents, 'ByaxWMaA', '-localized');
                                @file_put_contents($target, $fixedContents);
                                $registerTarget = preg_replace('/\.js$/', '-register-v2.js', $target);
                                @file_put_contents($registerTarget, $fixedContents);
                                continue;
                            }
                        }
                        if (str_starts_with($runtimeFile, 'assets/ByaxWMaA')
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            if ($assetContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::patchMessageLocalization($assetContents);
                                @file_put_contents($target, $fixedContents);
                                $localizedTarget = preg_replace('/\.js$/', '-localized.js', $target);
                                @file_put_contents($localizedTarget, $fixedContents);
                                continue;
                            }
                        }
                        if ((str_starts_with($runtimeFile, 'assets/C0KnXkt1')
                                || str_starts_with($runtimeFile, 'assets/C6e3mGRa'))
                            && str_ends_with($runtimeFile, '.js')) {
                            $assetContents = $loadingPatchedContents;
                            if ($assetContents !== false) {
                                $fixedContents = LuckThemeAssetPatcher::patchPaymentMessages($assetContents);
                                $isSubscriptionDialogAsset = str_starts_with($runtimeFile, 'assets/C6e3mGRa');
                                if ($isSubscriptionDialogAsset) {
                                    $fixedContents = LuckThemeAssetPatcher::patchSubscriptionDialogTeleport($fixedContents);
                                }
                                $fixedContents = LuckThemeAssetPatcher::rewriteSharedRuntimeAssetImport($fixedContents);
                                @file_put_contents($target, $fixedContents);
                                $paymentTarget = $isSubscriptionDialogAsset
                                    ? $publicThemePath . '/assets/' . LuckThemeAssetPatcher::subscriptionDialogAssetName($runtimeFile)
                                    : preg_replace('/\.js$/', '-payment-v4.js', $target);
                                @file_put_contents($paymentTarget, $fixedContents);
                                continue;
                            }
                        }
                        if ($loadingPatchedContents !== false) {
                            if (@file_put_contents($target, $loadingPatchedContents) === false) {
                                Log::warning('Theme JavaScript animation labels could not be localized', ['target' => $target]);
                            }
                            continue;
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
        abort(500, 'Không thể tải giao diện. Vui lòng thử lại.');
    }
};

$resourcesHost = env('LUCK_RESOURCES_HOST', 'resources.zaoguang-vpn.com');
Route::domain($resourcesHost)->group(function () {
    Route::get('/', [ResourcePortalController::class, 'index'])->name('resources.index');
    Route::get('/manage', [ResourcePortalController::class, 'manage'])->name('resources.manage');
});

Route::get('/', $renderTheme);

// Frontend-only preview for the future CNY QR checkout. It is deliberately
// absent from production route caches until a real provider and signed order
// flow are connected. The preview never creates an order or a payable QR.
if (app()->environment(['local', 'testing'])) {
    Route::get('/_preview/payment/china-wallets', function (Request $request) {
        $supportedLocales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];
        $requestedLocale = str_replace('_', '-', trim((string) $request->input('lang', 'zh-CN')));
        $locale = collect($supportedLocales)->first(
            static fn(string $supportedLocale): bool => strtolower($supportedLocale) === strtolower($requestedLocale)
        ) ?? 'zh-CN';
        $wallet = strtolower(trim((string) $request->input('wallet', 'wechatpay')));
        if (!in_array($wallet, ['wechatpay', 'alipay'], true)) {
            $wallet = 'wechatpay';
        }
        $amount = filter_var($request->input('amount', '88.00'), FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount <= 0 || $amount > 999999) {
            $amount = 88.00;
        }

        return response()
            ->view('payment.china-wallet-checkout', [
                'locale' => $locale,
                'previewMode' => true,
                'selectedWallet' => $wallet,
                'amountCny' => number_format((float) $amount, 2, '.', ''),
                'tradeNo' => 'CN-DEMO-' . now()->format('Ymd'),
                'returnUrl' => '/orders',
                'createEndpoint' => '',
                'expiresAt' => 0,
                'csrfToken' => '',
            ])
            ->header('Cache-Control', 'no-store, private, max-age=0');
    })->name('preview.payment.china-wallets');
}

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

    $supportedLocales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];
    $resolveLocale = static function (string $language) use ($supportedLocales): ?string {
        $normalized = strtolower(str_replace('_', '-', trim($language)));
        foreach ($supportedLocales as $supportedLocale) {
            if ($normalized === strtolower($supportedLocale)) {
                return $supportedLocale;
            }
        }
        if (preg_match('/^zh-(?:tw|hk|mo|hant)(?:-|$)/', $normalized)) {
            return 'zh-TW';
        }
        if (str_starts_with($normalized, 'zh')) {
            return 'zh-CN';
        }
        return match (strtok($normalized, '-')) {
            'vi' => 'vi-VN',
            'en' => 'en-US',
            'ja' => 'ja-JP',
            'ko' => 'ko-KR',
            'fa' => 'fa-IR',
            'ru' => 'ru-RU',
            default => null,
        };
    };

    $locale = 'en-US';
    foreach ($request->getLanguages() as $language) {
        $resolvedLocale = $resolveLocale((string) $language);
        if ($resolvedLocale !== null) {
            $locale = $resolvedLocale;
            break;
        }
    }
    if ($request->filled('lang')) {
        $requestedLocale = $resolveLocale((string) $request->input('lang'));
        if ($requestedLocale !== null) {
            $locale = $requestedLocale;
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
        // Keep status polling on the same host that serves the payment page.
        // Using APP_URL here makes the banking subdomain perform a CORS request
        // to the main panel, so successful payments appear to stay pending.
        'statusUrl' => '/payment/status/' . rawurlencode($order->trade_no),
        'returnUrl' => $panelUrl . '/orders?trade_no=' . rawurlencode($order->trade_no),
    ]);
})->where('tradeNo', '[A-Za-z0-9_-]+');

Route::get('/payment/status/{tradeNo}', function (string $tradeNo) {
    $order = Order::where('trade_no', $tradeNo)->first();
    if (!$order) {
        return response()->json(['message' => 'Order not found'], 404);
    }
    $expiresAt = (int) $order->created_at + (2 * 60 * 60);
    if (\Illuminate\Support\Facades\Schema::hasTable('v2_order_payment_checkout')
        && \Illuminate\Support\Facades\Schema::hasColumn('v2_order_payment_checkout', 'provider_expires_at')) {
        $providerExpiresAt = \Illuminate\Support\Facades\DB::table('v2_order_payment_checkout')
            ->where('order_id', $order->id)
            ->where('provider', 'CoinPayments')
            ->where('state', 'ready')
            ->value('provider_expires_at');
        if (is_numeric($providerExpiresAt) && (int) $providerExpiresAt > 0) {
            $expiresAt = (int) $providerExpiresAt;
        }
    }
    return response()->json([
        'status' => (int) $order->status,
        'expires_at' => $expiresAt,
    ])->withHeaders([
        'Cache-Control' => 'no-store, private, max-age=0',
        'Pragma' => 'no-cache',
    ]);
})->where('tradeNo', '[A-Za-z0-9_-]+');

// Same-site USDT TRC20 checkout. The public URL contains only a high-entropy
// token; trade numbers and sequential database identifiers are never route
// parameters, so order existence cannot be enumerated from this surface.
Route::get('/pay/usdt/{opaqueToken}', [UsdtDirectCheckoutController::class, 'show'])
    ->where('opaqueToken', '[A-Za-z0-9_-]{32,128}')
    ->name('payment.usdt-direct.show');
Route::get('/pay/usdt/{opaqueToken}/status', [UsdtDirectCheckoutController::class, 'status'])
    ->where('opaqueToken', '[A-Za-z0-9_-]{32,128}')
    ->name('payment.usdt-direct.status');
Route::get('/pay/usdt/{opaqueToken}/qr.svg', [UsdtDirectCheckoutController::class, 'qr'])
    ->where('opaqueToken', '[A-Za-z0-9_-]{32,128}')
    ->name('payment.usdt-direct.qr');

// The Luck theme is a history-mode SPA. Serve its shell for client-side
// routes as well, so refreshing /servers, /profile, /orders, etc. does not
// fall through to Laravel's 404 page before Vue Router can boot.
Route::get('/{path}', $renderTheme)->where('path', 'login|register|dashboard|plans|plans/purchase/[^/]+|servers|orders|tickets|traffic-details|invite|profile|docs');

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', 'Huy2006'), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => config('app.version', '1.0.0'),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', 'Huy2006')
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');
