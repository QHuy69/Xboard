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

    public static function patchNodeFlags(string $contents): string
    {
        if (str_contains($contents, 'class: "luck-node-flag"')) {
            return $contents;
        }

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
        if (!str_contains($contents, $countryNeedle)
            || !str_contains($contents, $imageNeedle)
            || !str_contains($contents, $nameNeedle)) {
            return $contents;
        }

        // The generated node table points at /flags/{code}.svg even though the
        // Luck distribution does not ship that directory. Every row therefore
        // renders an empty white image frame before the node name. Render the
        // ISO country code as a native flag glyph instead: it is local,
        // cache-independent and has an accessible country label.
        $contents = str_replace(
            $countryNeedle,
            <<<'JS'
          const countryInfo = getCountryInfo(row.name);
          const flagCode = /^[A-Z]{2}$/.test(String(countryInfo.code || "").toUpperCase()) ? String(countryInfo.code).toUpperCase() : "UN";
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
              "aria-label": countryInfo.name
            }, String.fromCodePoint(...flagCode.split("").map((character) => 127397 + character.charCodeAt(0)))),
JS,
            $contents
        );

        $contents = str_replace(
            $nameNeedle,
            'h("span", { style: { fontWeight: "600" } }, displayName)',
            $contents
        );

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
          customMessage.loginError("登录成功，但暂时无法加载账户信息，请重试");
        } else if (error.response && error.response.status !== 422) {
          const serverMessage = error.response.data && error.response.data.message;
          customMessage.loginError(serverMessage || "登录失败，请检查邮箱和密码");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
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
            ],
            [
                'if (formData.password.length < 8) {',
                'const loginData = { ...formData, email: formData.email.trim().toLowerCase() };',
                'const goToRegister = () => {' . "\n" . '      void router.push("/register");' . "\n" . '    };',
            ],
            $contents
        );

        return $contents;
    }

    public static function patchRegisterFlow(string $contents): string
    {
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
        if (error.response) {
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
        // A valid login can be followed by a transient /user/info failure.
        // Mark that stage explicitly and keep the token so the UI never lies
        // that the password was wrong. Xboard's User middleware returns 403
        // for a missing/expired token, while some auth stacks use 401; both
        // statuses prove that the saved token must be discarded.
        $contents = str_replace(
            <<<'JS'
      } else {
        logout();
        throw error;
      }
JS,
            <<<'JS'
      } else {
        if (error.response && (error.response.status === 401 || error.response.status === 403)) {
          logout();
        }
        error.luckAuthStage = "profile";
        throw error;
      }
JS,
            $contents
        );

        return $contents;
    }

    public static function patchPaymentMessages(string $contents): string
    {
        $contents = str_replace(
            'const paymentResult = await apiClient.checkoutOrder(tradeNo, method.id);',
            'const rawPaymentResult = await apiClient.checkoutOrder(tradeNo, method.id);' . "\n"
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
                'window.location.assign(paymentResult.data);',
                'window.location.assign(paymentResult.data);',
                'message.error(typeof window.__LUCK_T__ === "function" ? window.__LUCK_T__("未知的支付类型，请重试") : "Unknown payment method. Please try again.");',
            ],
            $contents
        );

        return $contents;
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
