<?php

namespace App\Services;

final class LuckThemeAssetPatcher
{
    private const FONT_FAMILY = '"Be Vietnam Pro", "Inter", "Segoe UI", Arial, sans-serif';

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
      if (backendConfig.value.is_invite_force && !formData.inviteCode.trim()) {
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
            'placeholder: backendConfig.value.is_invite_force ? "邀请码（必填）" : "邀请码（可选）",',
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
        // that the password was wrong. 401 is the only response that proves
        // the token itself is invalid.
        $contents = str_replace(
            <<<'JS'
      } else {
        logout();
        throw error;
      }
JS,
            <<<'JS'
      } else {
        if (error.response && error.response.status === 401) {
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
