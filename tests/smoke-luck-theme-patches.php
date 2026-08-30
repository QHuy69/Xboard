<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\LuckThemeAssetPatcher;

$entry = 'import("./BR9H_Zte-v3-fresh.js"); import("./CK-I2Xx_-v3-fresh.js"); import("./DSCv3-VU-v3-fresh.js"); import("./BBIEjj8f-v3-fresh.js"); import("./q_WC3BFv-v3-fresh.js"); import("./ByaxWMaA-v3-fresh.js"); import("./C0KnXkt1-v3-fresh.js");';
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'BR9H_Zte', '-localized');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'CK-I2Xx_', '-free');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'DSCv3-VU', '-managed');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'BBIEjj8f', '-auth-v3');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'q_WC3BFv', '-register-v2');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'ByaxWMaA', '-localized');
$entry = LuckThemeAssetPatcher::rewriteAssetImport($entry, 'C0KnXkt1', '-payment-v3');
if (!str_contains($entry, 'BR9H_Zte-v3-fresh-localized.js')
	|| !str_contains($entry, 'CK-I2Xx_-v3-fresh-free.js')
	|| !str_contains($entry, 'DSCv3-VU-v3-fresh-managed.js')
	|| !str_contains($entry, 'BBIEjj8f-v3-fresh-auth-v3.js')
	|| !str_contains($entry, 'q_WC3BFv-v3-fresh-register-v2.js')
	|| !str_contains($entry, 'ByaxWMaA-v3-fresh-localized.js')
	|| !str_contains($entry, 'C0KnXkt1-v3-fresh-payment-v3.js')) {
    fwrite(STDERR, "Luck asset cache-busting rewrite failed.\n");
    exit(1);
}

$entryAsset = getenv('LUCK_ENTRY_ASSET');
if ($entryAsset && is_file($entryAsset)) {
    $productionEntry = (string) file_get_contents($entryAsset);
    $productionEntry = LuckThemeAssetPatcher::rewriteAssetImport($productionEntry, 'BR9H_Zte', '-localized');
    $productionEntry = LuckThemeAssetPatcher::rewriteAssetImport($productionEntry, 'CK-I2Xx_', '-free');
    $productionEntry = LuckThemeAssetPatcher::rewriteAssetImport($productionEntry, 'DSCv3-VU', '-managed');
    $productionEntry = LuckThemeAssetPatcher::rewriteAssetImport($productionEntry, 'BBIEjj8f', '-auth-v3');
    $productionEntry = LuckThemeAssetPatcher::rewriteAssetImport($productionEntry, 'q_WC3BFv', '-register-v2');
    $productionEntry = LuckThemeAssetPatcher::rewriteAssetImport($productionEntry, 'ByaxWMaA', '-localized');
    $productionEntry = LuckThemeAssetPatcher::rewriteAssetImport($productionEntry, 'C0KnXkt1', '-payment-v3');
    if (!str_contains($productionEntry, 'BBIEjj8f-v3-fresh-auth-v3.js')) {
        fwrite(STDERR, "Luck production entry did not select the cache-busted login chunk.\n");
        exit(1);
    }
    file_put_contents(sys_get_temp_dir() . '/luck-entry-runtime-v2-check.js', $productionEntry);
}

$login = <<<'JS'
import { useAuthStore } from "./BBbuoBq5-v3-fresh.js";
        if (((_a2 = error.response) == null ? void 0 : _a2.status) === 500) {
          customMessage.loginError(((_b2 = error.response.data) == null ? void 0 : _b2.message) || "邮箱或密码错误");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
JS;
$login = LuckThemeAssetPatcher::patchLoginErrors($login);
$login = LuckThemeAssetPatcher::rewriteAssetImport($login, 'BBbuoBq5', '-runtime-v2');
if (!str_contains($login, 'error.response.status !== 422')
    || !str_contains($login, 'serverMessage')
    || !str_contains($login, 'error.luckAuthStage === "profile"')
    || !str_contains($login, 'BBbuoBq5-v3-fresh-runtime-v2.js')) {
    fwrite(STDERR, "Luck login-error classification patch failed.\n");
    exit(1);
}

$register = <<<'JS'
    const formData = reactive({
      email: "",
      emailPrefix: "",
      emailSuffix: "",
      password: "",
      confirmPassword: "",
      inviteCode: "",
      emailCode: ""
    });
      if (((_b2 = backendConfig.value) == null ? void 0 : _b2.is_email_verify) && !formData.emailCode.trim()) {
        customMessage.error("请输入邮箱验证码", { title: "验证码为空" });
        return;
      }
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
        placeholder: "邀请码（可选）",
JS;
$register = LuckThemeAssetPatcher::patchRegisterFlow($register);
if (!str_contains($register, 'const invitationCodeFromUrl =')
    || !str_contains($register, 'backendConfig.value.is_invite_force && !formData.inviteCode.trim()')
    || !str_contains($register, 'if (error.response)')
    || !str_contains($register, '邀请码（必填）')) {
    fwrite(STDERR, "Luck registration and invitation patch failed.\n");
    exit(1);
}

$sharedAuth = <<<'JS'
      } else {
        logout();
        throw error;
      }
JS;
$sharedAuth = LuckThemeAssetPatcher::patchSharedAuth($sharedAuth);
if (!str_contains($sharedAuth, 'error.response.status === 401')
    || !str_contains($sharedAuth, 'error.luckAuthStage = "profile"')) {
    fwrite(STDERR, "Luck authenticated-profile error patch failed.\n");
    exit(1);
}

$payment = <<<'JS'
        const paymentResult = await apiClient.checkoutOrder(tradeNo, method.id);
        if (paymentResult.type === 1) window.open(paymentResult.data, "_blank");
        if (paymentResult.type === 2) window.location.href = paymentResult.data;
        message.error("未知的支付类型，请重试");
JS;
$payment = LuckThemeAssetPatcher::patchPaymentMessages($payment);
if (!str_contains($payment, 'const rawPaymentResult =')
    || !str_contains($payment, 'Number(paymentResult.type) === 1')
    || substr_count($payment, 'window.location.assign(paymentResult.data)') !== 2
    || !str_contains($payment, '__LUCK_T__("未知的支付类型，请重试")')) {
    fwrite(STDERR, "Luck checkout normalization and redirect patch failed.\n");
    exit(1);
}

$loginAsset = getenv('LUCK_LOGIN_ASSET');
if ($loginAsset && is_file($loginAsset)) {
    $productionLogin = LuckThemeAssetPatcher::patchLoginErrors((string) file_get_contents($loginAsset));
    $productionLogin = LuckThemeAssetPatcher::rewriteAssetImport($productionLogin, 'BBbuoBq5', '-runtime-v2');
    if (!str_contains($productionLogin, 'error.response.status !== 422')
        || !str_contains($productionLogin, 'serverMessage')
        || !str_contains($productionLogin, 'BBbuoBq5-v3-fresh-runtime-v2.js')) {
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

echo "Luck auth, register, payment, chart, free-plan and invitation patches verified.\n";
