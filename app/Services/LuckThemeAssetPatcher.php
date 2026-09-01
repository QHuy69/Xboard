<?php

namespace App\Services;

final class LuckThemeAssetPatcher
{
    private const FONT_FAMILY = '"Be Vietnam Pro", "Inter", "Segoe UI", Arial, sans-serif';

    /**
     * Discover every compiled JavaScript chunk in a Luck theme.
     *
     * Lazy-route hashes and version suffixes change between Luck releases, so
     * keeping a hand-maintained filename list inevitably leaves some route
     * animations unpatched. Returning relative asset paths also keeps the
     * publishing loop independent from the host operating system separator.
     *
     * @return list<string>
     */
    public static function discoverJavascriptAssets(string $themePath): array
    {
        $assetPattern = rtrim($themePath, "\\/") . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . '*.js';
        $assets = [];

        foreach (glob($assetPattern) ?: [] as $asset) {
            if (is_file($asset)) {
                $assets[] = 'assets/' . basename($asset);
            }
        }

        $assets = array_values(array_unique($assets));
        sort($assets, SORT_STRING);

        return $assets;
    }

    public static function rewriteAssetImport(string $contents, string $assetStem, string $suffix): string
    {
        $pattern = '#(?<prefix>\./|assets/)(?<name>' . preg_quote($assetStem, '#') . '[^"\'\?]*\.js)(?:\?v=\d+)?#';

        return preg_replace_callback($pattern, static function (array $match) use ($suffix): string {
            $name = $match['name'];
            if (!str_ends_with($name, $suffix . '.js')) {
                $name = preg_replace('/\.js$/', $suffix . '.js', $name);
            }
            return $match['prefix'] . $name;
        }, $contents) ?? $contents;
    }

    /**
     * Force every lazy route through one cache-busted shared runtime. Route
     * chunks are cached independently from the entry module, so leaving even
     * one stock BBbuoBq5 import can revive an older subscription-dialog graph.
     */
    public static function rewriteSharedRuntimeAssetImport(string $contents): string
    {
        $pattern = '#(?<prefix>\./|assets/)(?<name>BBbuoBq5[^"\'\?]*\.js)(?<query>\?v=\d+)?#';

        return preg_replace_callback($pattern, static function (array $match): string {
            return $match['prefix'] . self::sharedRuntimeAssetName($match['name']) . ($match['query'] ?? '');
        }, $contents) ?? $contents;
    }

    public static function sharedRuntimeAssetName(string $assetName): string
    {
        $name = preg_replace('/(?:-runtime-v\d+)+\.js$/', '.js', basename($assetName)) ?? basename($assetName);

        return preg_replace('/\.js$/', '-runtime-v3.js', $name) ?? $name;
    }

    /** Cache-bust only the dashboard route that imports the shared runtime.
     * Versioning Vue's runtime itself would create two module identities and
     * break Teleport/ref ownership between the preloaded and lazy graphs. */
    public static function versionDashboardRouteAssetImport(string $contents): string
    {
        $pattern = '#(?<prefix>\./|assets/)(?<name>CO5Ntz5l[^"\'\?]*\.js)(?:\?v=\d+)?#';

        return preg_replace_callback($pattern, static function (array $match): string {
            return $match['prefix'] . $match['name'] . '?v=3';
        }, $contents) ?? $contents;
    }

    /**
     * Point every subscription-dialog lazy import at one normalized physical
     * chunk. Persistent Luck volumes can contain an older generated payment
     * suffix, so appending blindly would create payment-v4-payment-v6 chains.
     */
    public static function rewriteSubscriptionDialogAssetImport(string $contents): string
    {
        $pattern = '#(?<prefix>\./|assets/)(?<name>C6e3mGRa[^"\'\?]*\.js)(?<query>\?v=\d+)?#';

        return preg_replace_callback($pattern, static function (array $match): string {
            return $match['prefix'] . self::subscriptionDialogAssetName($match['name']) . ($match['query'] ?? '');
        }, $contents) ?? $contents;
    }

    public static function subscriptionDialogAssetName(string $assetName): string
    {
        $name = preg_replace('/(?:-payment(?:-v\d+)?)+\.js$/', '.js', basename($assetName)) ?? basename($assetName);

        return preg_replace('/\.js$/', '-payment-v6.js', $name) ?? $name;
    }

    /**
     * Rewrite every Luck node-chunk import to one physical cache-busted name.
     * Persistent theme volumes can already contain an older generated
     * `-access.js` variant, so normalizing the complete suffix chain first is
     * required for idempotency (`-access-access.js` must never be produced).
     */
    public static function rewriteNodeAssetImport(string $contents): string
    {
        $pattern = '#(?<prefix>\./|assets/)(?<name>oPGsis9D[^"\'\?]*\.js)(?:\?v=\d+)?#';

        return preg_replace_callback($pattern, static function (array $match): string {
            $name = preg_replace('/(?:-access(?:-v\d+)?)+\.js$/', '.js', $match['name']) ?? $match['name'];
            if (!str_ends_with($name, '-access-v2.js')) {
                $name = preg_replace('/\.js$/', '-access-v2.js', $name) ?? $name;
            }
            return $match['prefix'] . $name;
        }, $contents) ?? $contents;
    }

    public static function nodeAccessAssetName(string $assetName): string
    {
        $name = preg_replace('/(?:-access(?:-v\d+)?)+\.js$/', '.js', basename($assetName)) ?? basename($assetName);

        return preg_replace('/\.js$/', '-access-v2.js', $name) ?? $name;
    }

    /**
     * Give the three lazy routes containing maintained portable icons a new
     * browser/CDN URL without changing their physical generated filenames.
     */
    public static function versionPortableIconAssetImports(string $contents): string
    {
        $pattern = '#(?<prefix>\./|assets/)(?<name>(?:lsrL0SOU|BR9H_Zte|DSCv3-VU)[^"\'\?]*\.js)(?:\?v=\d+)?#';

        return preg_replace_callback($pattern, static function (array $match): string {
            return $match['prefix'] . $match['name'] . '?v=2';
        }, $contents) ?? $contents;
    }

    public static function patchLoadingAnimations(string $contents): string
    {
        // Luck renders route-level loading VNodes before the DOM translation
        // observer gets its first mutation. On an uncached lazy route this is
        // long enough for the original Chinese label to flash on screen. Run
        // those transient labels through the locale runtime while the VNode is
        // being created instead of translating them one frame later.
        $fallbacks = [
            'Loading...' => ['加载中...'],
            'Processing...' => ['处理中...'],
            'Signing in...' => ['登录中...'],
            'Resetting...' => ['重置中...'],
            'Sending...' => ['发送中...'],
            'Registering...' => ['注册中...'],
            'Redeeming...' => ['兑换中...'],
            'Adding funds...' => ['充值中...'],
            'Loading dashboard...' => ['正在加载主页数据...'],
            'Loading plans...' => ['加载套餐列表中...', '正在加载套餐信息...'],
            'Loading nodes...' => ['正在加载节点列表...'],
            ' Loading world map... ' => [' 正在加载世界地图... '],
            'Loading orders...' => ['加载订单中...'],
            'Loading ticket...' => ['工单内容加载中...'],
            'Loading documents...' => ['正在加载文档...'],
            'Loading document...' => ['正在加载文档内容...'],
            'Loading chart data...' => ['正在加载图表数据...'],
            'Loading traffic table...' => ['正在加载流量数据表...'],
            'Loading traffic data...' => ['流量数据加载中...'],
            'Loading payment methods...' => ['正在加载支付方式，请稍候...', '正在获取支付方式...'],
            'Creating order...' => ['正在创建订单...'],
            'Creating top-up order...' => ['正在创建充值订单...'],
            'Processing payment...' => ['正在处理支付...'],
            'Paying with balance...' => ['正在使用余额支付...'],
            'Completing balance payment...' => ['正在完成余额支付...'],
            'Checking payment status...' => ['正在检查支付状态...'],
            'Activating free order...' => ['正在激活免费订单...'],
            ' Generating QR code... ' => [' 正在生成二维码... '],
            'Opening payment...' => ['正在跳转支付...'],
            'Opening Alipay. Please complete payment.' => ['正在跳转到支付宝，请完成支付'],
            'Opening the payment app. Please complete payment.' => ['正在跳转到支付应用，请完成支付'],
            'Opening the payment page. Please complete payment.' => ['正在跳转到支付页面，请完成支付'],
            'Waiting for payment...' => ['等待支付中...'],
            'Loading invitation data...' => ['正在加载邀请数据...'],
        ];

        foreach ($fallbacks as $fallback => $sources) {
            foreach ($sources as $source) {
                $sourceJson = json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $fallbackJson = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $translatedExpression = '(typeof window.__LUCK_T__ === "function" ? window.__LUCK_T__(' . $sourceJson . ') : ' . $fallbackJson . ')';

                // Match only complete JS string literals used as values. The
                // negative lookbehind makes the transformation idempotent;
                // the negative lookahead avoids rewriting object keys.
                $pattern = '#(?<!__LUCK_T__\()(?<quote>["\x27\x60])'
                    . preg_quote($source, '#')
                    . '\k<quote>(?!\s*:)#u';
                $contents = preg_replace($pattern, $translatedExpression, $contents) ?? $contents;
            }
        }

        return $contents;
    }

    /**
     * Replace Luck's font/OS-dependent emoji glyphs with inline currentColor
     * SVG. These exact generated fragments cover the orders and traffic empty
     * states plus both desktop/mobile invite-transfer warning dialogs.
     * Decorative hosts remain aria-hidden because the adjacent localized text
     * already exposes their meaning to assistive technology.
     */
    public static function patchPortableUnicodeIcons(string $contents): string
    {
        $replacements = [
            'createBaseVNode("div", { class: "empty-icon" }, "📋", -1)' => <<<'JS'
createBaseVNode("div", { class: "empty-icon", "aria-hidden": "true" }, [
              createBaseVNode("svg", { class: "luck-portable-icon-svg", width: "48", height: "48", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", focusable: "false", "data-luck-icon": "orders-empty", style: { display: "block" } }, [
                createBaseVNode("path", { d: "M9 5h6m-6 4h6m-6 4h4M8 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-2m-8 0a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2H8V3Z", stroke: "currentColor", "stroke-width": "1.8", "stroke-linecap": "round", "stroke-linejoin": "round" })
              ])
            ], -1)
JS,
            'createBaseVNode("div", { class: "empty-icon" }, "📊", -1)' => <<<'JS'
createBaseVNode("div", { class: "empty-icon", "aria-hidden": "true" }, [
              createBaseVNode("svg", { class: "luck-portable-icon-svg", width: "48", height: "48", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", focusable: "false", "data-luck-icon": "traffic-empty", style: { display: "block" } }, [
                createBaseVNode("path", { d: "M4 19V9m6 10V5m6 14v-7m4 7H2", stroke: "currentColor", "stroke-width": "1.8", "stroke-linecap": "round", "stroke-linejoin": "round" })
              ])
            ], -1)
JS,
            'createBaseVNode("span", { class: "warning-icon" }, "⚠️")' => <<<'JS'
createBaseVNode("span", { class: "warning-icon", "aria-hidden": "true" }, [
              createBaseVNode("svg", { class: "luck-portable-icon-svg", width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", focusable: "false", "data-luck-icon": "warning", style: { display: "block" } }, [
                createBaseVNode("path", { d: "M12 3 2.8 20h18.4L12 3Zm0 6v4m0 3h.01", stroke: "currentColor", "stroke-width": "2", "stroke-linecap": "round", "stroke-linejoin": "round" })
              ])
            ])
JS,
            'createBaseVNode("span", { class: "warning-icon" }, "💰")' => <<<'JS'
createBaseVNode("span", { class: "warning-icon", "aria-hidden": "true" }, [
              createBaseVNode("svg", { class: "luck-portable-icon-svg", width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", focusable: "false", "data-luck-icon": "balance", style: { display: "block" } }, [
                createBaseVNode("path", { d: "M12 3a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 3v12m3-9.5c-.6-.7-1.5-1-3-1-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2c-1.5 0-2.4-.3-3-1", stroke: "currentColor", "stroke-width": "1.8", "stroke-linecap": "round", "stroke-linejoin": "round" })
              ])
            ])
JS,
            'createBaseVNode("span", { class: "warning-icon" }, "📝")' => <<<'JS'
createBaseVNode("span", { class: "warning-icon", "aria-hidden": "true" }, [
              createBaseVNode("svg", { class: "luck-portable-icon-svg", width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", focusable: "false", "data-luck-icon": "record", style: { display: "block" } }, [
                createBaseVNode("path", { d: "M7 3h7l4 4v14H7V3Zm7 0v5h5M9 12h6m-6 4h6", stroke: "currentColor", "stroke-width": "1.8", "stroke-linecap": "round", "stroke-linejoin": "round" })
              ])
            ])
JS,
            'createBaseVNode("span", { class: "hint-icon" }, "💡")' => <<<'JS'
createBaseVNode("span", { class: "hint-icon", "aria-hidden": "true" }, [
              createBaseVNode("svg", { class: "luck-portable-icon-svg", width: "18", height: "18", viewBox: "0 0 24 24", fill: "none", "aria-hidden": "true", focusable: "false", "data-luck-icon": "hint", style: { display: "block" } }, [
                createBaseVNode("path", { d: "M9 18h6m-5 3h4m3-9a5 5 0 1 0-8.5 3.6c.9.8 1.5 1.4 1.5 2.4h4c0-1 .6-1.6 1.5-2.4A4.9 4.9 0 0 0 17 12Z", stroke: "currentColor", "stroke-width": "1.8", "stroke-linecap": "round", "stroke-linejoin": "round" })
              ])
            ])
JS,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $contents);
    }

    /**
     * Keep the Clash subscription icon inside the packaged Luck theme. The
     * stock generated chunk points at a third-party hostname which can vanish
     * independently of Xboard; patching the VNode source also prevents a
     * broken-image flash before the dashboard enhancement observer runs.
     */
    public static function patchClashIcon(string $contents): string
    {
        return str_replace(
            'https://files.afeicloud.de/20250306201806786.webp',
            '/theme/Luck/assets/luck-clash.svg?v=2',
            $contents
        );
    }

    public static function patchTrafficChart(string $contents): string
    {
        if (!str_contains($contents, 'const luckChartText =')) {
            $contents = str_replace(
                '        const processedData = processChartData(chartData.value);' . "\n" . '        const option = {',
                '        const processedData = processChartData(chartData.value);' . "\n"
                    . '        const luckChartText = (value) => typeof window.__LUCK_T__ === "function" ? window.__LUCK_T__(value) : value;' . "\n"
                    . '        const option = {' . "\n"
                    . '          textStyle: {' . "\n"
                    . '            fontFamily: \'' . self::FONT_FAMILY . '\'' . "\n"
                    . '          },',
                $contents
            );
        }

        $contents = str_replace(
            [
                'text: `流量使用趋势 (最近30天)`',
                'data: ["上传流量", "下载流量"],' . "\n" . '            top: 30',
                'name: "流量 (GB)"',
                'name: "上传流量"',
                'name: "下载流量"',
                'fontWeight: "normal"' . "\n" . '            }',
                'top: "15%",' . "\n" . '            containLabel: true',
            ],
            [
                'text: luckChartText("流量使用趋势 (最近30天)")',
                'data: [luckChartText("上传流量"), luckChartText("下载流量")],' . "\n"
                    . '            top: 34,' . "\n"
                    . '            left: "center",' . "\n"
                    . '            itemGap: 32,' . "\n"
                    . '            textStyle: { fontFamily: \'' . self::FONT_FAMILY . '\', fontSize: 12 }',
                'name: luckChartText("流量 (GB)")',
                'name: luckChartText("上传流量")',
                'name: luckChartText("下载流量")',
                'fontWeight: "normal",' . "\n" . '              fontFamily: \'' . self::FONT_FAMILY . '\'' . "\n" . '            }',
                'top: 78,' . "\n" . '            containLabel: true',
            ],
            $contents
        );

        return $contents;
    }

    public static function patchNodeScrollbar(string $contents): string
    {
        if (str_contains($contents, '"scrollbar-props": { trigger: "none" }')) {
            return $contents;
        }

        $needle = <<<'JS'
              "scroll-x": 1200,
              class: "compact-table desktop-table"
JS;
        if (!str_contains($contents, $needle)) {
            return $contents;
        }

        return str_replace(
            $needle,
            <<<'JS'
              "scroll-x": 1200,
              "scrollbar-props": { trigger: "none" },
              class: "compact-table desktop-table"
JS,
            $contents
        );
    }

    public static function patchNodeFlags(string $contents): string
    {
        $countryNeedle = <<<'JS'
          const countryInfo = getCountryInfo(row.name);
          return h("div", { style: { display: "flex", alignItems: "center", gap: "6px" } }, [
JS;
        $imageNeedle = <<<'JS'
            h("img", {
              src: `/flags/${countryInfo.code.toLowerCase()}.svg`,
              alt: countryInfo.name,
              style: {
                width: "32px",
                height: "22px",
                borderRadius: "4px",
                border: "1px solid rgba(0,0,0,0.2)",
                flexShrink: "0",
                objectFit: "cover",
                boxShadow: "0 2px 6px rgba(0,0,0,0.15)"
              },
              onError: (e) => {
                const target = e.target;
                target.src = "/flags/un.svg";
              }
            }),
JS;
        $nameNeedle = 'h("span", { style: { fontWeight: "600" } }, row.name)';

        // Treat the generated fragment as one atomic patch. Partially
        // replacing only the image would reference undefined flagCode or
        // displayName variables after a Luck upstream update.
        if (!str_contains($contents, 'const flagAssetCode = packagedFlagCodes.has(flagCode)')
            && str_contains($contents, $countryNeedle)
            && str_contains($contents, $imageNeedle)
            && str_contains($contents, $nameNeedle)) {
            // The generated desktop node table points at /flags/{code}.svg
            // even though the Luck distribution does not ship that directory.
            // Regional-indicator emoji are not a safe fallback either:
            // Windows can render them as two letters or an empty box. Use our
            // packaged SVG sprite and retain a visible ISO badge so even an
            // unknown flag can never become blank.
            $contents = str_replace(
                $countryNeedle,
                <<<'JS'
          const countryInfo = getCountryInfo(row.name);
          const flagCode = /^[A-Z]{2}$/.test(String(countryInfo.code || "").toUpperCase()) ? String(countryInfo.code).toUpperCase() : "UN";
          const packagedFlagCodes = new Set(["AE", "AU", "BR", "CA", "CH", "CN", "DE", "DK", "ES", "FI", "FR", "GB", "HK", "ID", "IN", "IT", "JP", "KR", "MY", "NL", "NO", "PL", "RU", "SE", "SG", "TH", "TW", "US", "VN"]);
          const flagAssetCode = packagedFlagCodes.has(flagCode) ? flagCode.toLowerCase() : "un";
          const displayName = String(row.name || "").replace(/^\s*[\u{1F1E6}-\u{1F1FF}]{2}\s*/u, "") || String(row.name || "");
          return h("div", { style: { display: "flex", alignItems: "center", gap: "6px" } }, [
JS,
                $contents
            );

            $contents = str_replace(
                $imageNeedle,
                <<<'JS'
            h("span", {
              class: "luck-node-flag",
              role: "img",
              "aria-label": countryInfo.name,
              title: countryInfo.name
            }, [
              h("svg", {
                viewBox: "0 0 32 22",
                "aria-hidden": "true",
                focusable: "false"
              }, [
                h("use", { href: `/theme/Luck/assets/luck-flags.svg?v=1#${flagAssetCode}` })
              ]),
              h("span", {
                class: "luck-node-flag-code",
                "aria-hidden": "true"
              }, flagCode)
            ]),
JS,
                $contents
            );

            $contents = str_replace(
                $nameNeedle,
                'h("span", { style: { fontWeight: "600" } }, displayName)',
                $contents
            );
        }

        $mobileRenderNeedle = <<<'JS'
              (openBlock(true), createElementBlock(Fragment, null, renderList(servers.value, (server, index) => {
                return openBlock(), createElementBlock("div", {
JS;
        $mobileImageNeedle = <<<'JS'
                    createBaseVNode("img", {
                      src: `/flags/${getCountryInfo(server.name).code.toLowerCase()}.svg`,
                      alt: getCountryInfo(server.name).name,
                      style: { "width": "28px", "height": "19px", "border-radius": "3px", "border": "1px solid rgba(0,0,0,0.15)", "box-shadow": "0 1px 2px rgba(0,0,0,0.1)" },
                      onError: _cache[0] || (_cache[0] = (e) => e.target.src = "/flags/un.svg")
                    }, null, 40, _hoisted_9),
JS;
        $mobileNameNeedle = 'createBaseVNode("div", _hoisted_11, toDisplayString(server.name), 1),';

        // Luck has a separate card renderer below the desktop table for phone
        // and narrow-tablet layouts. Patch that complete renderer independently
        // so an already-patched desktop table can never make the mobile branch
        // return early and keep requesting the non-existent /flags directory.
        if (!str_contains($contents, 'const mobileFlagAssetCode = mobilePackagedFlagCodes.has(mobileFlagCode)')
            && str_contains($contents, $mobileRenderNeedle)
            && str_contains($contents, $mobileImageNeedle)
            && str_contains($contents, $mobileNameNeedle)) {
            $contents = str_replace(
                $mobileRenderNeedle,
                <<<'JS'
              (openBlock(true), createElementBlock(Fragment, null, renderList(servers.value, (server, index) => {
                const mobileCountryInfo = getCountryInfo(server.name);
                const mobileFlagCode = /^[A-Z]{2}$/.test(String(mobileCountryInfo.code || "").toUpperCase()) ? String(mobileCountryInfo.code).toUpperCase() : "UN";
                const mobilePackagedFlagCodes = new Set(["AE", "AU", "BR", "CA", "CH", "CN", "DE", "DK", "ES", "FI", "FR", "GB", "HK", "ID", "IN", "IT", "JP", "KR", "MY", "NL", "NO", "PL", "RU", "SE", "SG", "TH", "TW", "US", "VN"]);
                const mobileFlagAssetCode = mobilePackagedFlagCodes.has(mobileFlagCode) ? mobileFlagCode.toLowerCase() : "un";
                const mobileDisplayName = String(server.name || "").replace(/^\s*[\u{1F1E6}-\u{1F1FF}]{2}\s*/u, "") || String(server.name || "");
                return openBlock(), createElementBlock("div", {
JS,
                $contents
            );

            $contents = str_replace(
                $mobileImageNeedle,
                <<<'JS'
                    createBaseVNode("span", {
                      class: "luck-node-flag",
                      role: "img",
                      "aria-label": mobileCountryInfo.name,
                      title: mobileCountryInfo.name
                    }, [
                      createBaseVNode("svg", {
                        viewBox: "0 0 32 22",
                        "aria-hidden": "true",
                        focusable: "false"
                      }, [
                        createBaseVNode("use", { href: `/theme/Luck/assets/luck-flags.svg?v=1#${mobileFlagAssetCode}` })
                      ]),
                      createBaseVNode("span", {
                        class: "luck-node-flag-code",
                        "aria-hidden": "true"
                      }, mobileFlagCode)
                    ]),
JS,
                $contents
            );

            $contents = str_replace(
                $mobileNameNeedle,
                'createBaseVNode("div", _hoisted_11, toDisplayString(mobileDisplayName), 1),',
                $contents
            );
        }

        return $contents;
    }

    public static function patchFreePlans(string $contents): string
    {
        return str_replace(
            [
                'return plan.month_price || plan.quarter_price || plan.half_year_price || plan.year_price || plan.two_year_price || plan.three_year_price;',
                'return plan.onetime_price;',
            ],
            [
                'return plan.month_price != null || plan.quarter_price != null || plan.half_year_price != null || plan.year_price != null || plan.two_year_price != null || plan.three_year_price != null;',
                'return plan.onetime_price != null;',
            ],
            $contents
        );
    }

    public static function patchLoginErrors(string $contents): string
    {
        $contents = str_replace(
            <<<'JS'
        if (((_a2 = error.response) == null ? void 0 : _a2.status) === 500) {
          customMessage.loginError(((_b2 = error.response.data) == null ? void 0 : _b2.message) || "邮箱或密码错误");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
JS,
            <<<'JS'
        if (error && error.luckAuthStage === "profile") {
          const profileStatus = error.response && error.response.status;
          if (error.luckAuthFailure === "auth" || profileStatus === 401 || profileStatus === 403) {
            customMessage.loginError("身份验证失败，请重新登录");
          } else if (error.luckAuthFailure === "network" || (!error.luckAuthFailure && !error.response)) {
            customMessage.networkError();
          } else {
            const profileMessage = error.response && error.response.data && error.response.data.message;
            customMessage.loginError(profileMessage || "登录成功，但暂时无法加载账户信息，请重试");
          }
        } else if (error.response && error.response.status !== 422) {
          const serverMessage = error.response.data && error.response.data.message;
          customMessage.loginError(serverMessage || "登录失败，请检查邮箱和密码");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
JS,
            $contents
        );

        // Pinia's loading state changes synchronously inside login(), but an
        // explicit handler guard also protects keyboard submits and stale DOM
        // events from issuing a second request.
        if (!str_contains($contents, 'if (authStore.isLoading) return;')) {
            $contents = str_replace(
                'const handleLogin = async () => {',
                'const handleLogin = async () => {' . "\n" . '      if (authStore.isLoading) return;',
                $contents
            );
        }

        // Authentication is complete only after /user/info has populated the
        // store. Do not show a success toast or navigate on a token-only state.
        $contents = str_replace(
            <<<'JS'
        await authStore.login(loginData);
        customMessage.loginSuccess();
        router.push("/dashboard");
JS,
            <<<'JS'
        await authStore.login(loginData);
        if (!authStore.isAuthenticated) {
          const profileError = new Error("Authenticated user profile was not initialized");
          profileError.luckAuthStage = "profile";
          profileError.luckAuthFailure = "server";
          throw profileError;
        }
        customMessage.loginSuccess();
        if (router.currentRoute.value.path !== "/dashboard") {
          void router.replace("/dashboard").catch((navigationError) => {
            console.error("Post-login navigation failed:", navigationError);
          });
        }
JS,
            $contents
        );

        // Keep client-side validation aligned with AuthLogin::rules(). A six-
        // or seven-character password must never be sent only to come back as
        // a misleading request/network failure.
        $contents = str_replace(
            [
                'if (formData.password.length < 6) {',
                'const loginData = { ...formData };',
                'const goToRegister = () => {' . "\n" . '      router.push("/register");' . "\n" . '    };',
                'const goToRegister = () => {' . "\n" . '      void router.push("/register");' . "\n" . '    };',
            ],
            [
                'if (formData.password.length < 8) {',
                'const loginData = { ...formData, email: formData.email.trim().toLowerCase() };',
                'const goToRegister = () => {' . "\n" . '      if (router.currentRoute.value.path !== "/register") {' . "\n" . '        void router.push("/register").catch((navigationError) => {' . "\n" . '          console.error("Register navigation failed:", navigationError);' . "\n" . '        });' . "\n" . '      }' . "\n" . '    };',
                'const goToRegister = () => {' . "\n" . '      if (router.currentRoute.value.path !== "/register") {' . "\n" . '        void router.push("/register").catch((navigationError) => {' . "\n" . '          console.error("Register navigation failed:", navigationError);' . "\n" . '        });' . "\n" . '      }' . "\n" . '    };',
            ],
            $contents
        );

        return $contents;
    }

    public static function patchRegisterFlow(string $contents): string
    {
        // Registration spends time in apiClient.register() before Pinia's
        // setAuthData() toggles isLoading. Track the whole transaction so a
        // fast double click cannot create two accounts or two navigation jobs.
        if (!str_contains($contents, 'const registerSubmitting = ref(false);')) {
            $contents = str_replace(
                '    const authStore = useAuthStore();',
                '    const authStore = useAuthStore();' . "\n" . '    const registerSubmitting = ref(false);',
                $contents
            );
        }

        if (!str_contains($contents, 'if (registerSubmitting.value || authStore.isLoading) return;')) {
            $contents = str_replace(
                'const handleRegister = async () => {',
                'const handleRegister = async () => {' . "\n" . '      if (registerSubmitting.value || authStore.isLoading) return;',
                $contents
            );
        }

        // Invitation links must pre-fill the code regardless of whether the
        // current Luck bundle exposes Vue Router's useRoute helper.
        if (!str_contains($contents, 'const invitationCodeFromUrl =')) {
            $contents = str_replace(
                <<<'JS'
    const formData = reactive({
      email: "",
      emailPrefix: "",
      emailSuffix: "",
      password: "",
      confirmPassword: "",
      inviteCode: "",
      emailCode: ""
    });
JS,
                <<<'JS'
    const formData = reactive({
      email: "",
      emailPrefix: "",
      emailSuffix: "",
      password: "",
      confirmPassword: "",
      inviteCode: "",
      emailCode: ""
    });
    const invitationCodeFromUrl = new URLSearchParams(window.location.search).get("code");
    if (invitationCodeFromUrl) {
      formData.inviteCode = invitationCodeFromUrl.trim();
    }
JS,
                $contents
            );
        }

        // Validate a mandatory invitation code before the request. More
        // importantly, treat every HTTP response as an application response;
        // only a request with no response at all is a network error.
        if (!str_contains($contents, 'backendConfig.value.is_invite_force && !formData.inviteCode.trim()')) {
            $contents = str_replace(
                <<<'JS'
      if (((_b2 = backendConfig.value) == null ? void 0 : _b2.is_email_verify) && !formData.emailCode.trim()) {
        customMessage.error("请输入邮箱验证码", { title: "验证码为空" });
        return;
      }
JS,
                <<<'JS'
      if (((_b2 = backendConfig.value) == null ? void 0 : _b2.is_email_verify) && !formData.emailCode.trim()) {
        customMessage.error("请输入邮箱验证码", { title: "验证码为空" });
        return;
      }
      if (backendConfig.value && backendConfig.value.is_invite_force && !formData.inviteCode.trim()) {
        customMessage.registerError("You must use the invitation code to register");
        return;
      }
JS,
                $contents
            );
        }

        $contents = str_replace(
            <<<'JS'
        if (((_d2 = error.response) == null ? void 0 : _d2.status) === 500) {
          customMessage.registerError(((_e2 = error.response.data) == null ? void 0 : _e2.message) || "注册失败，请稍后重试");
        } else if (((_f2 = error.response) == null ? void 0 : _f2.status) === 422) {
          const errors = (_g2 = error.response.data) == null ? void 0 : _g2.errors;
          if (errors) {
            const errorMessages = Object.values(errors).flat();
            customMessage.registerError(errorMessages.join(", "));
          } else {
            customMessage.registerError("请检查输入信息");
          }
        } else {
          customMessage.networkError();
        }
JS,
            <<<'JS'
        if (error && error.luckAuthStage === "profile") {
          authStore.logout();
          customMessage.registerError("注册成功但未能自动登录，请手动登录");
          if (router.currentRoute.value.path !== "/login") {
            void router.replace("/login").catch((navigationError) => {
              console.error("Post-registration navigation failed:", navigationError);
            });
          }
        } else if (error.response) {
          const responseData = error.response.data || {};
          const errors = responseData.errors || responseData.error;
          if (errors && typeof errors === "object") {
            const errorMessages = Object.values(errors).flat().filter(Boolean);
            customMessage.registerError(errorMessages.join(", ") || responseData.message || "请检查输入信息");
          } else {
            customMessage.registerError(responseData.message || "注册失败，请检查输入信息");
          }
        } else {
          customMessage.networkError();
        }
JS,
            $contents
        );

        // Set the local lock immediately before the first registration
        // request, and release it for every success or failure path.
        if (!str_contains($contents, 'registerSubmitting.value = true;')) {
            $contents = str_replace(
                <<<'JS'
      try {
        const finalEmail =
JS,
                <<<'JS'
      registerSubmitting.value = true;
      try {
        const finalEmail =
JS,
                $contents
            );
        }

        $contents = str_replace(
            <<<'JS'
        const response = await apiClient.register(registerData);
        if (response.data) {
          await authStore.setAuthData(response.data);
          customMessage.registerSuccess();
          router.push("/dashboard");
        } else {
          customMessage.registerError("注册成功但未能自动登录，请手动登录");
          router.push("/login");
        }
JS,
            <<<'JS'
        const response = await apiClient.register(registerData);
        if (response.data) {
          await authStore.setAuthData(response.data);
          if (!authStore.isAuthenticated) {
            const profileError = new Error("Registered user profile was not initialized");
            profileError.luckAuthStage = "profile";
            profileError.luckAuthFailure = "server";
            throw profileError;
          }
          customMessage.registerSuccess();
          if (router.currentRoute.value.path !== "/dashboard") {
            void router.replace("/dashboard").catch((navigationError) => {
              console.error("Post-registration navigation failed:", navigationError);
            });
          }
        } else {
          authStore.logout();
          customMessage.registerError("注册成功但未能自动登录，请手动登录");
          if (router.currentRoute.value.path !== "/login") {
            void router.replace("/login").catch((navigationError) => {
              console.error("Post-registration navigation failed:", navigationError);
            });
          }
        }
JS,
            $contents
        );

        if (!str_contains($contents, 'registerSubmitting.value = false;')) {
            $contents = str_replace(
                <<<'JS'
        } else {
          customMessage.networkError();
        }
      }
JS,
                <<<'JS'
        } else {
          customMessage.networkError();
        }
      } finally {
        registerSubmitting.value = false;
      }
JS,
                $contents
            );
        }

        $contents = str_replace(
            [
                'disabled: unref(authStore).isLoading,',
                'loading: unref(authStore).isLoading,',
                'toDisplayString(unref(authStore).isLoading ? "注册中..." : "注册")',
            ],
            [
                'disabled: unref(authStore).isLoading || registerSubmitting.value,',
                'loading: unref(authStore).isLoading || registerSubmitting.value,' . "\n" . '                  disabled: unref(authStore).isLoading || registerSubmitting.value,',
                'toDisplayString(unref(authStore).isLoading || registerSubmitting.value ? "注册中..." : "注册")',
            ],
            $contents
        );

        return str_replace(
            'placeholder: "邀请码（可选）",',
            'placeholder: backendConfig.value && backendConfig.value.is_invite_force ? "邀请码（必填）" : "邀请码（可选）",',
            $contents
        );
    }

    public static function patchMessageLocalization(string $contents): string
    {
        // Toasts are Vue subtrees. Translating only the final DOM can split a
        // sentence into mixed Chinese/Vietnamese fragments, so translate the
        // content and title before the toast component renders them.
        foreach (['success', 'error', 'warning', 'info'] as $method) {
            $contents = str_replace(
                "    return this.instance.{$method}(content, options);",
                "    const translate = typeof window.__LUCK_T__ === \"function\" ? window.__LUCK_T__ : (value) => value;\n"
                    . "    const translatedOptions = options ? { ...options, title: options.title ? translate(options.title) : options.title } : options;\n"
                    . "    return this.instance.{$method}(translate(content), translatedOptions);",
                $contents
            );
        }

        return $contents;
    }

    public static function patchSharedAuth(string $contents): string
    {
        // Older Luck bundles persisted a fabricated user on /user/info 403.
        // That made `isAuthenticated` true, showed a success toast, and routed
        // to the dashboard even though Xboard had rejected the token. Remove
        // that placeholder from existing browser storage during bootstrap.
        if (!str_contains($contents, 'const restoredUser = JSON.parse(savedUser);')) {
            $contents = str_replace(
                <<<'JS'
    if (savedUser) {
      try {
        user.value = JSON.parse(savedUser);
      } catch (error) {
        console.error("解析用户信息失败:", error);
        localStorage.removeItem("v2board_user");
      }
    }
JS,
                <<<'JS'
    if (savedUser) {
      try {
        const restoredUser = JSON.parse(savedUser);
        const isLegacyPlaceholder = restoredUser && Number(restoredUser.id) === 0 && restoredUser.email === "unknown@example.com";
        if (!restoredUser || typeof restoredUser !== "object" || Array.isArray(restoredUser) || isLegacyPlaceholder) {
          token.value = "";
          user.value = null;
          localStorage.removeItem("v2board_token");
          localStorage.removeItem("v2board_user");
          apiClient.clearAuthToken();
        } else {
          user.value = restoredUser;
        }
      } catch (error) {
        console.error("解析用户信息失败:", error);
        token.value = "";
        user.value = null;
        localStorage.removeItem("v2board_token");
        localStorage.removeItem("v2board_user");
        apiClient.clearAuthToken();
      }
    }
JS,
                $contents
            );
        }

        // A token is not a completed login. /user/info must return a real
        // object before the store can persist a user. 401/403 are auth
        // failures (Xboard's User middleware uses 403); HTTP failures with a
        // response are server/application errors, and only no-response cases
        // are reported as network errors.
        $fetchUserStart = strpos($contents, '  const fetchUserInfo = async () => {');
        $checkAuthStart = $fetchUserStart === false
            ? false
            : strpos($contents, '  const checkAuth = async () => {', $fetchUserStart);

        if ($fetchUserStart !== false && $checkAuthStart !== false) {
            $fetchUserInfo = <<<'JS'
  const fetchUserInfo = async () => {
    try {
      const userInfo = await apiClient.getUserInfo();
      const isLegacyPlaceholder = userInfo && Number(userInfo.id) === 0 && userInfo.email === "unknown@example.com";
      if (!userInfo || typeof userInfo !== "object" || Array.isArray(userInfo) || isLegacyPlaceholder) {
        const invalidProfileError = new Error("Invalid user profile response");
        invalidProfileError.luckAuthStage = "profile";
        invalidProfileError.luckAuthFailure = "server";
        throw invalidProfileError;
      }
      user.value = userInfo;
      localStorage.setItem("v2board_user", JSON.stringify(userInfo));
    } catch (error) {
      console.error("获取用户信息失败:", error);
      const profileError = error && typeof error === "object" ? error : new Error("Unable to load user profile");
      const profileStatus = profileError.response && profileError.response.status;
      const isAuthFailure = profileStatus === 401 || profileStatus === 403;
      user.value = null;
      localStorage.removeItem("v2board_user");
      if (isAuthFailure) {
        logout();
      }
      profileError.luckAuthStage = "profile";
      profileError.luckAuthFailure = profileError.luckAuthFailure || (isAuthFailure ? "auth" : (profileError.response ? "server" : "network"));
      throw profileError;
    }
  };

JS;
            $contents = substr_replace(
                $contents,
                $fetchUserInfo,
                $fetchUserStart,
                $checkAuthStart - $fetchUserStart
            );
        }

        return $contents;
    }

    public static function patchPaymentMessages(string $contents): string
    {
        $coinPaymentsBridge = <<<'JS'
        if (typeof window.__LUCK_OPEN_COINPAYMENTS_PAYMENT__ !== "function") {
          window.__LUCK_OPEN_COINPAYMENTS_PAYMENT__ = function(checkoutUrl, tradeNo) {
            const supportedHost = /^(?:[a-c]-)?checkout\.coinpayments\.net$/i;
            let safeCheckoutUrl = null;
            try {
              const candidate = new URL(String(checkoutUrl || ""));
              const safePort = candidate.port === "" || candidate.port === "443";
              if (candidate.protocol === "https:" && supportedHost.test(candidate.hostname) && safePort && !candidate.username && !candidate.password) {
                safeCheckoutUrl = candidate;
              }
            } catch (invalidCheckoutUrl) {
              safeCheckoutUrl = null;
            }

            const language = String(document.documentElement.lang || (window.V2BOARD_CONFIG && window.V2BOARD_CONFIG.LANGUAGE) || "en-US").replace(/_/g, "-");
            const copy = {
              "vi-VN": { title: "Thanh toán bằng CoinPayments", subtitle: "Hoàn tất thanh toán an toàn ngay tại ZaoGuang Service", order: "Đơn hàng", secure: "Kết nối bảo mật", loading: "Đang tải cổng thanh toán...", waiting: "Đang chờ CoinPayments xác nhận thanh toán", checking: "Đang kiểm tra trạng thái...", paid: "Thanh toán thành công. Đang quay về đơn hàng...", cancelled: "Đơn hàng đã bị hủy.", error: "Chưa thể kiểm tra trạng thái. Hệ thống sẽ tự thử lại.", invalid: "Liên kết CoinPayments không hợp lệ. Không có trang bên ngoài nào được mở.", frameHelp: "Nếu khung thanh toán không hiển thị, hãy dùng nút Mở CoinPayments.", open: "Mở CoinPayments", back: "Quay lại đơn hàng", close: "Đóng cửa sổ thanh toán", check: "Kiểm tra thanh toán", remaining: "Thời gian còn lại", expired: "Đã hết thời gian thanh toán", frameTitle: "Cổng thanh toán CoinPayments" },
              "en-US": { title: "Pay with CoinPayments", subtitle: "Complete your secure payment inside ZaoGuang Service", order: "Order", secure: "Secure connection", loading: "Loading payment gateway...", waiting: "Waiting for CoinPayments confirmation", checking: "Checking payment status...", paid: "Payment successful. Returning to your order...", cancelled: "This order was cancelled.", error: "The status could not be checked. We will retry automatically.", invalid: "The CoinPayments link is invalid. No external page was opened.", frameHelp: "If the payment frame does not appear, use Open CoinPayments.", open: "Open CoinPayments", back: "Back to orders", close: "Close payment window", check: "Check payment", remaining: "Time remaining", expired: "Payment time expired", frameTitle: "CoinPayments checkout" },
              "zh-CN": { title: "使用 CoinPayments 支付", subtitle: "在 ZaoGuang Service 内安全完成付款", order: "订单", secure: "安全连接", loading: "正在加载支付网关...", waiting: "正在等待 CoinPayments 确认付款", checking: "正在检查付款状态...", paid: "支付成功，正在返回订单...", cancelled: "订单已取消。", error: "暂时无法检查状态，系统会自动重试。", invalid: "CoinPayments 链接无效，未打开任何外部页面。", frameHelp: "如果支付窗口未显示，请使用“打开 CoinPayments”。", open: "打开 CoinPayments", back: "返回订单", close: "关闭付款窗口", check: "检查付款", remaining: "剩余时间", expired: "支付时间已结束", frameTitle: "CoinPayments 收银台" },
              "zh-TW": { title: "使用 CoinPayments 付款", subtitle: "在 ZaoGuang Service 內安全完成付款", order: "訂單", secure: "安全連線", loading: "正在載入付款閘道...", waiting: "正在等待 CoinPayments 確認付款", checking: "正在檢查付款狀態...", paid: "付款成功，正在返回訂單...", cancelled: "訂單已取消。", error: "暫時無法檢查狀態，系統會自動重試。", invalid: "CoinPayments 連結無效，未開啟任何外部頁面。", frameHelp: "如果付款視窗未顯示，請使用「開啟 CoinPayments」。", open: "開啟 CoinPayments", back: "返回訂單", close: "關閉付款視窗", check: "檢查付款", remaining: "剩餘時間", expired: "付款時間已結束", frameTitle: "CoinPayments 收銀台" },
              "ja-JP": { title: "CoinPayments で支払う", subtitle: "ZaoGuang Service 内で安全にお支払いを完了できます", order: "注文", secure: "安全な接続", loading: "決済画面を読み込んでいます...", waiting: "CoinPayments の確認を待っています", checking: "支払い状況を確認しています...", paid: "支払いが完了しました。注文画面に戻ります...", cancelled: "注文はキャンセルされました。", error: "状態を確認できませんでした。自動的に再試行します。", invalid: "CoinPayments のリンクが無効です。外部ページは開かれていません。", frameHelp: "決済画面が表示されない場合は、「CoinPayments を開く」を使用してください。", open: "CoinPayments を開く", back: "注文に戻る", close: "決済画面を閉じる", check: "支払いを確認", remaining: "残り時間", expired: "支払い期限切れ", frameTitle: "CoinPayments 決済" },
              "ko-KR": { title: "CoinPayments로 결제", subtitle: "ZaoGuang Service 안에서 안전하게 결제를 완료하세요", order: "주문", secure: "보안 연결", loading: "결제 화면을 불러오는 중...", waiting: "CoinPayments 결제 확인을 기다리는 중", checking: "결제 상태를 확인하는 중...", paid: "결제가 완료되었습니다. 주문으로 돌아갑니다...", cancelled: "주문이 취소되었습니다.", error: "상태를 확인하지 못했습니다. 자동으로 다시 시도합니다.", invalid: "CoinPayments 링크가 올바르지 않아 외부 페이지를 열지 않았습니다.", frameHelp: "결제 화면이 보이지 않으면 CoinPayments 열기를 사용하세요.", open: "CoinPayments 열기", back: "주문으로 돌아가기", close: "결제 창 닫기", check: "결제 확인", remaining: "남은 시간", expired: "결제 시간 만료", frameTitle: "CoinPayments 결제" },
              "fa-IR": { title: "پرداخت با CoinPayments", subtitle: "پرداخت امن را در ZaoGuang Service تکمیل کنید", order: "سفارش", secure: "اتصال امن", loading: "در حال بارگذاری درگاه پرداخت...", waiting: "در انتظار تأیید CoinPayments", checking: "در حال بررسی وضعیت پرداخت...", paid: "پرداخت موفق بود. در حال بازگشت به سفارش...", cancelled: "این سفارش لغو شده است.", error: "وضعیت بررسی نشد. سامانه خودکار دوباره تلاش می‌کند.", invalid: "پیوند CoinPayments نامعتبر است و صفحه خارجی باز نشد.", frameHelp: "اگر درگاه نمایش داده نشد، از دکمه باز کردن CoinPayments استفاده کنید.", open: "باز کردن CoinPayments", back: "بازگشت به سفارش‌ها", close: "بستن پنجره پرداخت", check: "بررسی پرداخت", remaining: "زمان باقی‌مانده", expired: "زمان پرداخت پایان یافت", frameTitle: "درگاه CoinPayments" },
              "ru-RU": { title: "Оплата через CoinPayments", subtitle: "Завершите безопасную оплату внутри ZaoGuang Service", order: "Заказ", secure: "Защищённое соединение", loading: "Загрузка платёжного шлюза...", waiting: "Ожидание подтверждения CoinPayments", checking: "Проверяем статус платежа...", paid: "Платёж выполнен. Возвращаемся к заказу...", cancelled: "Заказ был отменён.", error: "Не удалось проверить статус. Система повторит попытку автоматически.", invalid: "Недействительная ссылка CoinPayments. Внешняя страница не была открыта.", frameHelp: "Если форма оплаты не появилась, нажмите «Открыть CoinPayments».", open: "Открыть CoinPayments", back: "Назад к заказам", close: "Закрыть окно оплаты", check: "Проверить оплату", remaining: "Осталось времени", expired: "Время оплаты истекло", frameTitle: "Оплата CoinPayments" }
            };
            const normalizedLanguage = language.toLowerCase();
            const exactLocale = Object.keys(copy).find(function(locale) { return locale.toLowerCase() === normalizedLanguage; });
            const primaryLanguage = normalizedLanguage.split("-")[0];
            const fallbackLocale = primaryLanguage === "zh"
              ? (/-(?:tw|hk|mo|hant)(?:-|$)/.test(normalizedLanguage) ? "zh-TW" : "zh-CN")
              : ({ vi: "vi-VN", en: "en-US", ja: "ja-JP", ko: "ko-KR", fa: "fa-IR", ru: "ru-RU" }[primaryLanguage] || "en-US");
            const selectedLocale = exactLocale || fallbackLocale;
            const labels = copy[selectedLocale];
            const current = document.querySelector("[data-luck-coinpayments-checkout]");
            if (current && typeof current.__luckCleanup === "function") current.__luckCleanup();

            if (!document.getElementById("luck-coinpayments-checkout-style")) {
              const style = document.createElement("style");
              style.id = "luck-coinpayments-checkout-style";
              style.textContent = ".luck-cp-lock{overflow:hidden!important}.luck-cp-overlay{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;padding:clamp(10px,2vw,28px);background:rgba(15,23,42,.66);backdrop-filter:blur(10px);overscroll-behavior:contain}.luck-cp-card{width:min(1120px,100%);height:min(880px,calc(100vh - 32px));height:min(880px,calc(100dvh - 32px));display:grid;grid-template-rows:auto auto minmax(0,1fr) auto;overflow:hidden;border:1px solid rgba(255,255,255,.72);border-radius:24px;background:#fff;box-shadow:0 30px 90px rgba(15,23,42,.35);color:#27364a;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif}.luck-cp-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:22px 24px;background:linear-gradient(135deg,#49bfa7,#6ed5bf);color:#fff}.luck-cp-heading{min-width:0}.luck-cp-header h1{margin:0 0 5px;font-size:clamp(20px,2.2vw,27px);line-height:1.2}.luck-cp-header p{margin:0;font-size:14px;opacity:.92}.luck-cp-order{margin-top:8px!important;font-weight:650;overflow-wrap:anywhere}.luck-cp-close{flex:0 0 42px;width:42px;height:42px;border:1px solid rgba(255,255,255,.55);border-radius:13px;background:rgba(255,255,255,.17);color:#fff;cursor:pointer;font-size:26px;line-height:1}.luck-cp-state{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 24px;border-bottom:1px solid #e7f1ef;background:#f6fafb;font-size:14px}.luck-cp-state-text{display:flex;align-items:center;gap:9px;min-width:0;font-weight:650}.luck-cp-status{overflow-wrap:anywhere}.luck-cp-dot{flex:0 0 9px;width:9px;height:9px;border-radius:50%;background:#e6a23c;box-shadow:0 0 0 5px rgba(230,162,60,.13)}.luck-cp-state[data-state=paid] .luck-cp-dot{background:#20b486;box-shadow:0 0 0 5px rgba(32,180,134,.13)}.luck-cp-state[data-state=error] .luck-cp-dot,.luck-cp-state[data-state=cancelled] .luck-cp-dot{background:#e45b5b;box-shadow:0 0 0 5px rgba(228,91,91,.13)}.luck-cp-secure{white-space:nowrap;color:#4f6b67;font-size:13px}.luck-cp-frame-wrap{position:relative;min-height:0;background:#eef3f6}.luck-cp-frame{display:block;width:100%;height:100%;border:0;background:#fff}.luck-cp-loader{position:absolute;inset:0;z-index:1;display:grid;place-items:center;padding:24px;background:linear-gradient(135deg,#f6fafb,#edf7ff);color:#627083;text-align:center}.luck-cp-loader[hidden]{display:none}.luck-cp-footer{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:16px;padding:15px 20px;border-top:1px solid #e7eef1;background:#fff}.luck-cp-meta{min-width:0;color:#627083;font-size:13px;line-height:1.45}.luck-cp-countdown{font-weight:700;color:#a3650b}.luck-cp-help{margin-top:3px}.luck-cp-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:9px}.luck-cp-btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border:0;border-radius:11px;text-align:center;text-decoration:none;cursor:pointer;font:650 14px/1.25 Inter,ui-sans-serif,system-ui;color:#475569;background:#edf3f5}.luck-cp-btn-primary{background:#49bfa7;color:#fff}.luck-cp-btn-outline{border:1px solid #badbd4;background:#fff;color:#258c76}.luck-cp-btn[hidden]{display:none}.luck-cp-btn:focus-visible,.luck-cp-close:focus-visible{outline:3px solid rgba(37,99,235,.35);outline-offset:2px}@media(max-width:700px){.luck-cp-overlay{place-items:stretch;padding:0}.luck-cp-card{width:100%;height:100vh;height:100dvh;max-height:none;border:0;border-radius:0}.luck-cp-header{padding:calc(15px + env(safe-area-inset-top)) 16px 14px}.luck-cp-state{padding:10px 16px}.luck-cp-secure{display:none}.luck-cp-footer{grid-template-columns:1fr;padding:11px 12px calc(11px + env(safe-area-inset-bottom));gap:9px}.luck-cp-meta{text-align:center}.luck-cp-actions{display:grid;grid-template-columns:1fr 1fr}.luck-cp-btn{padding-inline:10px}.luck-cp-btn-primary{grid-column:1/-1;grid-row:1}.luck-cp-frame-wrap{min-height:0}}@media(max-width:380px){.luck-cp-actions{grid-template-columns:1fr}.luck-cp-btn-primary{grid-column:auto}.luck-cp-help{display:none}}@media(max-height:520px) and (orientation:landscape){.luck-cp-header{padding:8px 14px}.luck-cp-heading>p:not(.luck-cp-order){display:none}.luck-cp-order{margin-top:3px!important}.luck-cp-state{padding:7px 14px}.luck-cp-footer{grid-template-columns:auto minmax(0,1fr);padding:7px 10px;gap:8px}.luck-cp-help{display:none}.luck-cp-actions{display:flex;flex-wrap:nowrap}.luck-cp-btn{min-height:38px;padding:7px 10px;font-size:12px}.luck-cp-frame-wrap{min-height:0}}";
              document.head.appendChild(style);
            }

            const make = function(tag, className, text) {
              const node = document.createElement(tag);
              if (className) node.className = className;
              if (typeof text === "string") node.textContent = text;
              return node;
            };
            const overlay = make("div", "luck-cp-overlay");
            overlay.setAttribute("data-luck-coinpayments-checkout", "1");
            overlay.setAttribute("role", "dialog");
            overlay.setAttribute("aria-modal", "true");
            overlay.setAttribute("aria-label", labels.title);
            overlay.dir = selectedLocale === "fa-IR" ? "rtl" : "ltr";
            const card = make("section", "luck-cp-card");
            const header = make("header", "luck-cp-header");
            const heading = make("div", "luck-cp-heading");
            heading.appendChild(make("h1", "", labels.title));
            heading.appendChild(make("p", "", labels.subtitle));
            heading.appendChild(make("p", "luck-cp-order", labels.order + " " + String(tradeNo || "")));
            const close = make("button", "luck-cp-close", "×");
            close.type = "button";
            close.setAttribute("aria-label", labels.close);
            header.append(heading, close);
            const state = make("div", "luck-cp-state");
            state.setAttribute("role", "status");
            state.setAttribute("aria-live", "polite");
            state.setAttribute("aria-atomic", "true");
            state.dataset.state = safeCheckoutUrl ? "waiting" : "error";
            const stateText = make("span", "luck-cp-state-text");
            stateText.append(make("span", "luck-cp-dot"), make("span", "luck-cp-status", safeCheckoutUrl ? labels.waiting : labels.invalid));
            state.append(stateText, make("span", "luck-cp-secure", labels.secure));
            const frameWrap = make("div", "luck-cp-frame-wrap");
            const loader = make("div", "luck-cp-loader", safeCheckoutUrl ? labels.loading : labels.invalid);
            frameWrap.appendChild(loader);
            let frame = null;
            if (safeCheckoutUrl) {
              frame = make("iframe", "luck-cp-frame");
              frame.title = labels.frameTitle;
              frame.src = safeCheckoutUrl.href;
              frame.allow = "clipboard-read; clipboard-write; payment";
              frame.referrerPolicy = "strict-origin-when-cross-origin";
              frame.addEventListener("load", function() { loader.hidden = true; });
              frameWrap.appendChild(frame);
            }
            const footer = make("footer", "luck-cp-footer");
            const meta = make("div", "luck-cp-meta");
            const countdown = make("div", "luck-cp-countdown", labels.remaining + ": 02:00:00");
            const help = make("div", "luck-cp-help", safeCheckoutUrl ? labels.frameHelp : labels.invalid);
            meta.append(countdown, help);
            const actions = make("div", "luck-cp-actions");
            const external = make("a", "luck-cp-btn luck-cp-btn-outline", labels.open);
            if (safeCheckoutUrl) {
              external.href = safeCheckoutUrl.href;
              external.target = "_blank";
              external.rel = "noopener noreferrer";
            } else {
              external.hidden = true;
            }
            const back = make("button", "luck-cp-btn", labels.back);
            back.type = "button";
            const check = make("button", "luck-cp-btn luck-cp-btn-primary", labels.check);
            check.type = "button";
            actions.append(external, back, check);
            footer.append(meta, actions);
            card.append(header, state, frameWrap, footer);
            overlay.appendChild(card);
            document.body.appendChild(overlay);
            document.documentElement.classList.add("luck-cp-lock");
            document.body.classList.add("luck-cp-lock");

            const statusLabel = state.querySelector(".luck-cp-status");
            const returnUrl = "/orders?trade_no=" + encodeURIComponent(String(tradeNo || ""));
            const statusUrl = "/payment/status/" + encodeURIComponent(String(tradeNo || ""));
            const previousFocus = document.activeElement;
            let expiresAt = Date.now() + (2 * 60 * 60 * 1000);
            let pollTimer = null;
            let clockTimer = null;
            let routeTimer = null;
            let redirectTimer = null;
            let polling = false;
            let disposed = false;
            const initialRoute = window.location.pathname + window.location.search + window.location.hash;
            const isCurrent = function() {
              return !disposed && document.querySelector("[data-luck-coinpayments-checkout]") === overlay;
            };
            const setState = function(kind, text) {
              state.dataset.state = kind;
              statusLabel.textContent = text;
            };
            const tick = function() {
              const seconds = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
              if (seconds === 0) {
                countdown.textContent = labels.expired;
                return;
              }
              const hours = String(Math.floor(seconds / 3600)).padStart(2, "0");
              const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
              const remainder = String(seconds % 60).padStart(2, "0");
              countdown.textContent = labels.remaining + ": " + hours + ":" + minutes + ":" + remainder;
            };
            const cleanup = function() {
              if (disposed) return;
              disposed = true;
              if (pollTimer) window.clearInterval(pollTimer);
              if (clockTimer) window.clearInterval(clockTimer);
              if (routeTimer) window.clearInterval(routeTimer);
              if (redirectTimer) window.clearTimeout(redirectTimer);
              document.removeEventListener("keydown", onKeydown);
              window.removeEventListener("popstate", onRouteChange);
              window.removeEventListener("hashchange", onRouteChange);
              window.removeEventListener("pagehide", cleanup);
              document.documentElement.classList.remove("luck-cp-lock");
              document.body.classList.remove("luck-cp-lock");
              overlay.remove();
              if (previousFocus && typeof previousFocus.focus === "function") previousFocus.focus();
            };
            const goBack = function() {
              cleanup();
              window.location.assign(returnUrl);
            };
            const poll = async function() {
              if (polling || disposed || !tradeNo) return;
              polling = true;
              setState("checking", labels.checking);
              try {
                const response = await fetch(statusUrl, { headers: { Accept: "application/json" }, credentials: "same-origin", cache: "no-store" });
                if (!isCurrent()) return;
                if (!response.ok) throw new Error("payment status unavailable");
                const payload = await response.json();
                if (!isCurrent()) return;
                if (payload && Number(payload.expires_at) > 0) expiresAt = Number(payload.expires_at) * 1000;
                const orderStatus = Number(payload && typeof payload.status !== "undefined" ? payload.status : payload && payload.data);
                if (orderStatus === 1 || orderStatus === 3) {
                  setState("paid", labels.paid);
                  check.disabled = true;
                  external.hidden = true;
                  if (pollTimer) window.clearInterval(pollTimer);
                  redirectTimer = window.setTimeout(goBack, 1100);
                } else if (orderStatus === 2) {
                  setState("cancelled", labels.cancelled);
                  check.disabled = true;
                  external.hidden = true;
                  if (pollTimer) window.clearInterval(pollTimer);
                } else {
                  setState("waiting", labels.waiting);
                }
              } catch (statusError) {
                if (isCurrent()) setState("error", labels.error);
              } finally {
                polling = false;
              }
            };
            function onKeydown(event) {
              if (event.key === "Escape") {
                cleanup();
                return;
              }
              if (event.key !== "Tab" || !isCurrent()) return;
              const focusable = Array.from(overlay.querySelectorAll('button:not([disabled]):not([hidden]),a[href]:not([hidden]),iframe:not([hidden])'));
              if (!focusable.length) return;
              const first = focusable[0];
              const last = focusable[focusable.length - 1];
              if (!overlay.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
              } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
              } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
              }
            }
            function onRouteChange() {
              cleanup();
            }
            overlay.__luckCleanup = cleanup;
            close.addEventListener("click", cleanup);
            back.addEventListener("click", goBack);
            check.addEventListener("click", poll);
            document.addEventListener("keydown", onKeydown);
            window.addEventListener("popstate", onRouteChange);
            window.addEventListener("hashchange", onRouteChange);
            window.addEventListener("pagehide", cleanup);
            tick();
            clockTimer = window.setInterval(tick, 1000);
            routeTimer = window.setInterval(function() {
              if (window.location.pathname + window.location.search + window.location.hash !== initialRoute) cleanup();
            }, 250);
            poll();
            pollTimer = window.setInterval(poll, 5000);
            close.focus();
            return Boolean(safeCheckoutUrl);
          };
        }
JS;
        $contents = str_replace(
            'const paymentResult = await apiClient.checkoutOrder(tradeNo, method.id);',
            $coinPaymentsBridge . "\n"
                . '        const rawPaymentResult = await apiClient.checkoutOrder(tradeNo, method.id);' . "\n"
                . '        const paymentResult = rawPaymentResult && rawPaymentResult.data && typeof rawPaymentResult.type === "undefined" ? rawPaymentResult.data : rawPaymentResult;',
            $contents
        );

        // Laravel returns an integer today, but older cached API wrappers can
        // expose the same value as a string or under `data`. Normalise both
        // shapes so the first checkout attempt cannot fall into "unknown".
        $contents = str_replace('paymentResult.type ===', 'Number(paymentResult.type) ===', $contents);
        $contents = str_replace(
            [
                'window.open(paymentResult.data, "_blank");',
                'window.location.href = paymentResult.data;',
                'message.error("未知的支付类型，请重试");',
            ],
            [
                '(String(method && method.payment || "").toLowerCase() === "coinpayments" ? window.__LUCK_OPEN_COINPAYMENTS_PAYMENT__(paymentResult.data, tradeNo) : window.location.assign(paymentResult.data));',
                'window.location.assign(paymentResult.data);',
                'message.error(typeof window.__LUCK_T__ === "function" ? window.__LUCK_T__("未知的支付类型，请重试") : "Unknown payment method. Please try again.");',
            ],
            $contents
        );

        return $contents;
    }

    /**
     * Let Vue own the subscription dialog's move to document.body. A raw DOM
     * append escapes Vue's responsive route tree and leaves a dead overlay
     * behind when the mobile/desktop shell is swapped after device rotation.
     * Teleport preserves props/listeners while guaranteeing unmount cleanup.
     */
    public static function patchSubscriptionDialogTeleport(string $contents): string
    {
        if (str_contains($contents, 'name: "PortalledSubscriptionDialog"')) {
            return $contents;
        }
        if (!str_contains($contents, '__name: "SubscriptionDialog"')) {
            return $contents;
        }

        $importPattern = '#import \{(?<bindings>[^{}]+)\} from "(?<runtime>\./DM1yaN1X[^"]*\.js)";#';
        $componentPattern = '#const SubscriptionDialog = /\* @__PURE__ \*/ _export_sfc\([^;]+\);#';
        if (preg_match_all($importPattern, $contents, $importMatches) !== 1
            || preg_match_all($componentPattern, $contents, $componentMatches) !== 1) {
            return $contents;
        }

        $patched = preg_replace_callback(
            $importPattern,
            static fn(array $match): string => 'import {' . rtrim($match['bindings'])
                . ', T as Teleport } from "' . $match['runtime'] . '";',
            $contents,
            1
        );
        if ($patched === null) {
            return $contents;
        }

        $componentDeclaration = $componentMatches[0][0];
        $stockDeclaration = preg_replace(
            '/^const SubscriptionDialog =/',
            'const StockSubscriptionDialog =',
            $componentDeclaration,
            1
        );
        if ($stockDeclaration === null || $stockDeclaration === $componentDeclaration) {
            return $contents;
        }

        $wrapper = <<<'JS'
const SubscriptionDialog = /* @__PURE__ */ defineComponent({
  name: "PortalledSubscriptionDialog",
  inheritAttrs: false,
  setup(_props, { attrs }) {
    return () => createVNode(Teleport, { to: "body" }, [
      createVNode(StockSubscriptionDialog, attrs)
    ]);
  }
});
JS;

        return str_replace(
            $componentDeclaration,
            $stockDeclaration . "\n" . $wrapper,
            $patched
        );
    }

    public static function patchInviteManagement(string $contents): string
    {
        if (!str_contains($contents, 'const revokeInviteCode = async')) {
            $revokeFunction = <<<'JS'
  const revokeInviteCode = async (code) => {
    const translate = typeof window.__LUCK_T__ === "function" ? window.__LUCK_T__ : (value) => value;
    if (!window.confirm(translate("\u786e\u5b9a\u8981\u7981\u7528\u6b64\u9080\u8bf7\u7801\u5417\uff1f"))) return;
    try {
      await apiClient.instance.post("/api/v1/user/invite/revoke", { code });
      await loadInviteStats();
      message.success(translate("\u9080\u8bf7\u7801\u5df2\u7981\u7528"));
    } catch (error) {
      console.error("\u7981\u7528\u9080\u8bf7\u7801\u5931\u8d25:", error);
      message.error(translate("\u7981\u7528\u9080\u8bf7\u7801\u5931\u8d25\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5"));
    }
  };
JS;
            $contents = str_replace(
                '  const copyToClipboard = async (text) => {',
                $revokeFunction . "\n" . '  const copyToClipboard = async (text) => {',
                $contents
            );
            $contents = str_replace(
                'generateInviteCode,' . "\n",
                'generateInviteCode,' . "\n" . '      revokeInviteCode,' . "\n",
                $contents
            );
        }

        if (!str_contains($contents, 'unref(revokeInviteCode)(code.code)')) {
            $buttonNeedle = <<<'JS'
                    }, 1032, ["onClick"])
                  ])
                ]);
JS;
            $buttonReplacement = <<<'JS'
                    }, 1032, ["onClick"]),
                    createVNode(_component_n_button, {
                      size: "small",
                      onClick: ($event) => unref(revokeInviteCode)(code.code),
                      type: "error",
                      class: "action-btn-small"
                    }, {
                      default: withCtx(() => [createTextVNode(" \u7981\u7528 ")]),
                      _: 2
                    }, 1032, ["onClick"])
                  ])
                ]);
JS;
            $contents = str_replace($buttonNeedle, $buttonReplacement, $contents);
        }

        return $contents;
    }
}
