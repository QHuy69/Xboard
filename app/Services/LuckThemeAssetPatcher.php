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
        return str_replace(
            <<<'JS'
        if (((_a2 = error.response) == null ? void 0 : _a2.status) === 500) {
          customMessage.loginError(((_b2 = error.response.data) == null ? void 0 : _b2.message) || "邮箱或密码错误");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
JS,
            <<<'JS'
        if (error.response && error.response.status !== 422) {
          const serverMessage = error.response.data && error.response.data.message;
          customMessage.loginError(serverMessage || "登录失败，请检查邮箱和密码");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
JS,
            $contents
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
