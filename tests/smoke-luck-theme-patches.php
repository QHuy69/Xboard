<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\LuckThemeAssetPatcher;

$entry = 'import("./BR9H_Zte-v3-fresh.js"); import("./CK-I2Xx_-v3-fresh.js"); import("./DSCv3-VU-v3-fresh.js"); import("./BBIEjj8f-v3-fresh.js");';
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'BR9H_Zte', '-localized');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'CK-I2Xx_', '-free');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'DSCv3-VU', '-managed');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'BBIEjj8f', '-errors');
if (!str_contains($entry, 'BR9H_Zte-v3-fresh-localized.js')
	|| !str_contains($entry, 'CK-I2Xx_-v3-fresh-free.js')
	|| !str_contains($entry, 'DSCv3-VU-v3-fresh-managed.js')
	|| !str_contains($entry, 'BBIEjj8f-v3-fresh-errors.js')) {
    fwrite(STDERR, "Luck asset cache-busting rewrite failed.\n");
    exit(1);
}

$login = <<<'JS'
        if (((_a2 = error.response) == null ? void 0 : _a2.status) === 500) {
          customMessage.loginError(((_b2 = error.response.data) == null ? void 0 : _b2.message) || "邮箱或密码错误");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
JS;
$login = LuckThemeAssetPatcher::patchLoginErrors($login);
if (!str_contains($login, 'error.response.status !== 422') || !str_contains($login, 'serverMessage')) {
    fwrite(STDERR, "Luck login-error classification patch failed.\n");
    exit(1);
}

$loginAsset = getenv('LUCK_LOGIN_ASSET');
if ($loginAsset && is_file($loginAsset)) {
    $productionLogin = LuckThemeAssetPatcher::patchLoginErrors((string) file_get_contents($loginAsset));
    if (!str_contains($productionLogin, 'error.response.status !== 422')
        || !str_contains($productionLogin, 'serverMessage')) {
        fwrite(STDERR, "Luck production login chunk was not patched.\n");
        exit(1);
    }
    file_put_contents(sys_get_temp_dir() . '/luck-login-errors-check.js', $productionLogin);
}

$chart = <<<'JS'
        const processedData = processChartData(chartData.value);
        const option = {
          title: {
            text: `流量使用趋势 (最近30天)`,
            textStyle: {
              fontSize: 16,
              fontWeight: "normal"
            }
          },
          legend: {
            data: ["上传流量", "下载流量"],
            top: 30
          },
          grid: {
            top: "15%",
            containLabel: true
          },
          yAxis: { name: "流量 (GB)" },
          series: [{ name: "上传流量" }, { name: "下载流量" }]
        };
JS;
$chart = LuckThemeAssetPatcher::patchTrafficChart($chart);
if (!str_contains($chart, 'luckChartText("流量使用趋势 (最近30天)")')
    || !str_contains($chart, 'itemGap: 32')
    || !str_contains($chart, 'fontFamily:')
    || !str_contains($chart, 'name: luckChartText("下载流量")')) {
    fwrite(STDERR, "Luck traffic-chart patch failed.\n");
    exit(1);
}

$plans = 'return plan.month_price || plan.quarter_price || plan.half_year_price || plan.year_price || plan.two_year_price || plan.three_year_price; return plan.onetime_price;';
$plans = LuckThemeAssetPatcher::patchFreePlans($plans);
if (!str_contains($plans, 'plan.month_price != null') || !str_contains($plans, 'plan.onetime_price != null')) {
    fwrite(STDERR, "Luck zero-price plan patch failed.\n");
    exit(1);
}

$invite = <<<'JS'
  const copyToClipboard = async (text) => {
  };
    generateInviteCode,
      generateInviteCode,
                    }, 1032, ["onClick"])
                  ])
                ]);
JS;
$invite = LuckThemeAssetPatcher::patchInviteManagement($invite);
if (!str_contains($invite, '/api/v1/user/invite/revoke')
    || !str_contains($invite, 'unref(revokeInviteCode)(code.code)')) {
    fwrite(STDERR, "Luck invitation-management patch failed.\n");
    exit(1);
}

echo "Luck chart, free-plan, invitation and login patches verified.\n";
