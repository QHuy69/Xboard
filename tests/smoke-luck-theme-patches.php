<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\LuckThemeAssetPatcher;

$animationAssetRoot = sys_get_temp_dir() . '/luck-animation-assets-' . bin2hex(random_bytes(6));
$animationAssetDirectory = $animationAssetRoot . '/assets';
$animationAssetNames = [
    'Dg9FJUWi.js',
    'Dg9FJUWi-v2.js',
    'lsrL0SOU.js',
    'lsrL0SOU-v7-fresh.js',
    'yuHauBoh.js',
    'CO5Ntz5l-v3.js',
    '_d5ASL-Z.js',
    'oPGsis9D-v2-access.js',
];
mkdir($animationAssetDirectory, 0777, true);
foreach ($animationAssetNames as $animationAssetName) {
    file_put_contents($animationAssetDirectory . '/' . $animationAssetName, 'export default "加载中...";');
}
file_put_contents($animationAssetDirectory . '/ignored.css', '.loader{}');

$discoveredAnimationAssets = LuckThemeAssetPatcher::discoverJavascriptAssets($animationAssetRoot);
$expectedAnimationAssets = array_map(static fn(string $name): string => 'assets/' . $name, $animationAssetNames);
sort($expectedAnimationAssets, SORT_STRING);
foreach (glob($animationAssetDirectory . '/*') ?: [] as $animationAssetPath) {
    unlink($animationAssetPath);
}
rmdir($animationAssetDirectory);
rmdir($animationAssetRoot);

if ($discoveredAnimationAssets !== $expectedAnimationAssets) {
    fwrite(STDERR, "Luck lazy JavaScript chunk discovery failed.\n");
    exit(1);
}

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

$loadingAnimationSources = [
    '加载中...',
    '处理中...',
    '登录中...',
    '重置中...',
    '发送中...',
    '注册中...',
    '兑换中...',
    '充值中...',
    '正在加载主页数据...',
    '加载套餐列表中...',
    '正在加载套餐信息...',
    '正在加载节点列表...',
    ' 正在加载世界地图... ',
    '加载订单中...',
    '工单内容加载中...',
    '正在加载文档...',
    '正在加载文档内容...',
    '正在加载图表数据...',
    '正在加载流量数据表...',
    '流量数据加载中...',
    '正在加载支付方式，请稍候...',
    '正在获取支付方式...',
    '正在创建订单...',
    '正在创建充值订单...',
    '正在处理支付...',
    '正在使用余额支付...',
    '正在完成余额支付...',
    '正在检查支付状态...',
    '正在激活免费订单...',
    ' 正在生成二维码... ',
    '正在跳转支付...',
    '正在跳转到支付宝，请完成支付',
    '正在跳转到支付应用，请完成支付',
    '正在跳转到支付页面，请完成支付',
    '等待支付中...',
    '正在加载邀请数据...',
];
$loadingFixture = 'const states = [' . implode(', ', array_map(
    static fn(string $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    $loadingAnimationSources
)) . ']; const labels = {"加载中...": true}; const alreadyLocalized = window.__LUCK_T__("加载中...");';
$localizedLoadingFixture = LuckThemeAssetPatcher::patchLoadingAnimations($loadingFixture);
if (substr_count($localizedLoadingFixture, 'typeof window.__LUCK_T__ === "function"') !== count($loadingAnimationSources)
    || !str_contains($localizedLoadingFixture, 'const labels = {"加载中...": true}')
    || !str_contains($localizedLoadingFixture, 'const alreadyLocalized = window.__LUCK_T__("加载中...")')
    || LuckThemeAssetPatcher::patchLoadingAnimations($localizedLoadingFixture) !== $localizedLoadingFixture) {
    fwrite(STDERR, "Luck route-loading animation localization patch failed.\n");
    exit(1);
}

$nodeFlagFixture = <<<'JS'
          const countryInfo = getCountryInfo(row.name);
          return h("div", { style: { display: "flex", alignItems: "center", gap: "6px" } }, [
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
            h("span", { style: { fontWeight: "600" } }, row.name)
          ]);
JS;
$patchedNodeFlagFixture = LuckThemeAssetPatcher::patchNodeFlags($nodeFlagFixture);
if (str_contains($patchedNodeFlagFixture, '/flags/')
    || !str_contains($patchedNodeFlagFixture, 'class: "luck-node-flag"')
    || !str_contains($patchedNodeFlagFixture, '"aria-label": countryInfo.name')
    || !str_contains($patchedNodeFlagFixture, '/theme/Luck/assets/luck-flags.svg?v=1#${flagAssetCode}')
    || !str_contains($patchedNodeFlagFixture, 'class: "luck-node-flag-code"')
    || str_contains($patchedNodeFlagFixture, 'String.fromCodePoint')
    || !str_contains($patchedNodeFlagFixture, 'displayName')
    || LuckThemeAssetPatcher::patchNodeFlags($patchedNodeFlagFixture) !== $patchedNodeFlagFixture) {
    fwrite(STDERR, "Luck node flag replacement failed.\n");
    exit(1);
}

$incompleteNodeFlagFixture = str_replace(
    'h("span", { style: { fontWeight: "600" } }, row.name)',
    'h("span", row.name)',
    $nodeFlagFixture
);
if (LuckThemeAssetPatcher::patchNodeFlags($incompleteNodeFlagFixture) !== $incompleteNodeFlagFixture
    || str_contains(LuckThemeAssetPatcher::patchNodeFlags($incompleteNodeFlagFixture), 'flagCode')) {
    fwrite(STDERR, "Luck node flag patch must be atomic when upstream markup changes.\n");
    exit(1);
}

$mobileNodeFlagFixture = <<<'JS'
              (openBlock(true), createElementBlock(Fragment, null, renderList(servers.value, (server, index) => {
                return openBlock(), createElementBlock("div", {
                  key: `mobile-${server.type}-${server.id}`,
                  class: "mobile-server-item"
                }, [
                  createBaseVNode("div", _hoisted_8, [
                    createBaseVNode("img", {
                      src: `/flags/${getCountryInfo(server.name).code.toLowerCase()}.svg`,
                      alt: getCountryInfo(server.name).name,
                      style: { "width": "28px", "height": "19px", "border-radius": "3px", "border": "1px solid rgba(0,0,0,0.15)", "box-shadow": "0 1px 2px rgba(0,0,0,0.1)" },
                      onError: _cache[0] || (_cache[0] = (e) => e.target.src = "/flags/un.svg")
                    }, null, 40, _hoisted_9),
                    createBaseVNode("div", _hoisted_10, [
                      createBaseVNode("div", _hoisted_11, toDisplayString(server.name), 1),
                    ])
                  ])
                ]);
              }))
JS;
$patchedMobileNodeFlagFixture = LuckThemeAssetPatcher::patchNodeFlags($mobileNodeFlagFixture);
if (str_contains($patchedMobileNodeFlagFixture, '/flags/')
    || !str_contains($patchedMobileNodeFlagFixture, 'const mobileFlagCode =')
    || !str_contains($patchedMobileNodeFlagFixture, 'const mobileFlagAssetCode =')
    || !str_contains($patchedMobileNodeFlagFixture, 'class: "luck-node-flag"')
    || !str_contains($patchedMobileNodeFlagFixture, '"aria-label": mobileCountryInfo.name')
    || !str_contains($patchedMobileNodeFlagFixture, '/theme/Luck/assets/luck-flags.svg?v=1#${mobileFlagAssetCode}')
    || !str_contains($patchedMobileNodeFlagFixture, 'class: "luck-node-flag-code"')
    || !str_contains($patchedMobileNodeFlagFixture, 'toDisplayString(mobileDisplayName)')
    || LuckThemeAssetPatcher::patchNodeFlags($patchedMobileNodeFlagFixture) !== $patchedMobileNodeFlagFixture) {
    fwrite(STDERR, "Luck mobile node-card flag replacement failed.\n");
    exit(1);
}

$combinedNodeFlagFixture = $nodeFlagFixture . "\n" . $mobileNodeFlagFixture;
$patchedCombinedNodeFlagFixture = LuckThemeAssetPatcher::patchNodeFlags($combinedNodeFlagFixture);
if (str_contains($patchedCombinedNodeFlagFixture, '/flags/')
    || substr_count($patchedCombinedNodeFlagFixture, 'class: "luck-node-flag"') !== 2
    || substr_count($patchedCombinedNodeFlagFixture, 'luck-flags.svg?v=1#${') !== 2
    || !str_contains($patchedCombinedNodeFlagFixture, 'const flagAssetCode =')
    || !str_contains($patchedCombinedNodeFlagFixture, 'const mobileFlagAssetCode =')) {
    fwrite(STDERR, "Luck desktop and mobile node flags must be patched in the same pass.\n");
    exit(1);
}

$incompleteMobileNodeFlagFixture = str_replace(
    'createBaseVNode("div", _hoisted_11, toDisplayString(server.name), 1),',
    'createBaseVNode("div", toDisplayString(server.name), 1),',
    $mobileNodeFlagFixture
);
if (LuckThemeAssetPatcher::patchNodeFlags($incompleteMobileNodeFlagFixture) !== $incompleteMobileNodeFlagFixture
    || str_contains(LuckThemeAssetPatcher::patchNodeFlags($incompleteMobileNodeFlagFixture), 'mobileFlagCode')) {
    fwrite(STDERR, "Luck mobile node flag patch must be atomic when upstream markup changes.\n");
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
    const handleLogin = async () => {
      var _a2, _b2, _c2, _d2;
      try {
        const loginData = { ...formData };
        await authStore.login(loginData);
        customMessage.loginSuccess();
        router.push("/dashboard");
      } catch (error) {
        if (((_a2 = error.response) == null ? void 0 : _a2.status) === 500) {
          customMessage.loginError(((_b2 = error.response.data) == null ? void 0 : _b2.message) || "邮箱或密码错误");
        } else if (((_c2 = error.response) == null ? void 0 : _c2.status) === 422) {
        }
      }
    };
    const goToRegister = () => {
      router.push("/register");
    };
JS;
$login = LuckThemeAssetPatcher::patchLoginErrors($login);
$login = LuckThemeAssetPatcher::rewriteAssetImport($login, 'BBbuoBq5', '-runtime-v2');
if (!str_contains($login, 'error.response.status !== 422')
    || !str_contains($login, 'serverMessage')
    || !str_contains($login, 'error.luckAuthStage === "profile"')
    || !str_contains($login, 'error.luckAuthFailure === "auth"')
    || !str_contains($login, 'if (authStore.isLoading) return;')
    || !str_contains($login, 'if (!authStore.isAuthenticated)')
    || !str_contains($login, 'customMessage.loginSuccess();')
    || strpos($login, 'if (!authStore.isAuthenticated)') > strpos($login, 'customMessage.loginSuccess();')
    || !str_contains($login, 'router.currentRoute.value.path !== "/dashboard"')
    || !str_contains($login, 'router.currentRoute.value.path !== "/register"')
    || str_contains($login, 'router.push("/dashboard")')
    || !str_contains($login, 'BBbuoBq5-v3-fresh-runtime-v2.js')) {
    fwrite(STDERR, "Luck login-error classification patch failed.\n");
    exit(1);
}
if (LuckThemeAssetPatcher::patchLoginErrors($login) !== $login) {
    fwrite(STDERR, "Luck login patch must be idempotent.\n");
    exit(1);
}

$register = <<<'JS'
    const authStore = useAuthStore();
    const formData = reactive({
      email: "",
      emailPrefix: "",
      emailSuffix: "",
      password: "",
      confirmPassword: "",
      inviteCode: "",
      emailCode: ""
    });
    const handleRegister = async () => {
      var _a2, _b2, _c2, _d2, _e2, _f2, _g2;
      if (((_b2 = backendConfig.value) == null ? void 0 : _b2.is_email_verify) && !formData.emailCode.trim()) {
        customMessage.error("请输入邮箱验证码", { title: "验证码为空" });
        return;
      }
      try {
        const finalEmail = formData.email;
        const registerData = { email: finalEmail, password: formData.password };
        const response = await apiClient.register(registerData);
        if (response.data) {
          await authStore.setAuthData(response.data);
          customMessage.registerSuccess();
          router.push("/dashboard");
        } else {
          customMessage.registerError("注册成功但未能自动登录，请手动登录");
          router.push("/login");
        }
      } catch (error) {
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
      }
    };
                createVNode(_component_n_button, {
                  disabled: unref(authStore).isLoading,
                  loading: unref(authStore).isLoading,
                  onClick: handleRegister
                }, {
                  default: withCtx(() => [
                    createTextVNode(toDisplayString(unref(authStore).isLoading ? "注册中..." : "注册"), 1)
                  ])
                });
        placeholder: "邀请码（可选）",
JS;
$register = LuckThemeAssetPatcher::patchRegisterFlow($register);
if (!str_contains($register, 'const invitationCodeFromUrl =')
    || !str_contains($register, 'const registerSubmitting = ref(false);')
    || !str_contains($register, 'if (registerSubmitting.value || authStore.isLoading) return;')
    || !str_contains($register, 'registerSubmitting.value = true;')
    || !str_contains($register, 'registerSubmitting.value = false;')
    || !str_contains($register, 'backendConfig.value && backendConfig.value.is_invite_force && !formData.inviteCode.trim()')
    || !str_contains($register, 'placeholder: backendConfig.value && backendConfig.value.is_invite_force')
    || !str_contains($register, 'if (error && error.luckAuthStage === "profile")')
    || !str_contains($register, 'authStore.logout();')
    || !str_contains($register, 'if (!authStore.isAuthenticated)')
    || strpos($register, 'if (!authStore.isAuthenticated)') > strpos($register, 'customMessage.registerSuccess();')
    || !str_contains($register, 'router.currentRoute.value.path !== "/dashboard"')
    || !str_contains($register, 'router.currentRoute.value.path !== "/login"')
    || !str_contains($register, 'loading: unref(authStore).isLoading || registerSubmitting.value')
    || !str_contains($register, 'disabled: unref(authStore).isLoading || registerSubmitting.value')
    || str_contains($register, 'router.push("/dashboard")')
    || !str_contains($register, '邀请码（必填）')) {
    fwrite(STDERR, "Luck registration and invitation patch failed.\n");
    exit(1);
}
if (LuckThemeAssetPatcher::patchRegisterFlow($register) !== $register) {
    fwrite(STDERR, "Luck registration patch must be idempotent.\n");
    exit(1);
}

$sharedAuth = <<<'JS'
  const initAuth = () => {
    const savedToken = localStorage.getItem("v2board_token");
    const savedUser = localStorage.getItem("v2board_user");
    if (savedToken) {
      token.value = savedToken;
      apiClient.setAuthToken(savedToken);
    }
    if (savedUser) {
      try {
        user.value = JSON.parse(savedUser);
      } catch (error) {
        console.error("解析用户信息失败:", error);
        localStorage.removeItem("v2board_user");
      }
    }
  };
  const fetchUserInfo = async () => {
    var _a;
    try {
      const userInfo = await apiClient.getUserInfo();
      user.value = userInfo;
      localStorage.setItem("v2board_user", JSON.stringify(userInfo));
    } catch (error) {
      if (((_a = error.response) == null ? void 0 : _a.status) === 403) {
        user.value = { id: 0, email: "unknown@example.com" };
        localStorage.setItem("v2board_user", JSON.stringify(user.value));
      } else {
        logout();
        throw error;
      }
    }
  };
  const checkAuth = async () => {
    return true;
  };
JS;
$sharedAuth = LuckThemeAssetPatcher::patchSharedAuth($sharedAuth);
if (!str_contains($sharedAuth, 'const restoredUser = JSON.parse(savedUser);')
    || !str_contains($sharedAuth, 'isLegacyPlaceholder')
    || !str_contains($sharedAuth, 'profileStatus === 401 || profileStatus === 403')
    || !str_contains($sharedAuth, 'profileError.luckAuthStage = "profile"')
    || !str_contains($sharedAuth, 'isAuthFailure ? "auth"')
    || !str_contains($sharedAuth, 'profileError.response ? "server" : "network"')
    || !str_contains($sharedAuth, 'user.value = null;')
    || str_contains($sharedAuth, 'user.value = { id: 0')
    || str_contains($sharedAuth, 'JSON.stringify(user.value)')) {
    fwrite(STDERR, "Luck authenticated-profile error patch failed.\n");
    exit(1);
}
if (LuckThemeAssetPatcher::patchSharedAuth($sharedAuth) !== $sharedAuth) {
    fwrite(STDERR, "Luck shared-auth patch must be idempotent.\n");
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

$overrideCss = (string) file_get_contents(dirname(__DIR__) . '/luck-overrides.css');
$dashboardTemplate = (string) file_get_contents(dirname(__DIR__) . '/luck-dashboard.blade.php');
if (!str_contains($overrideCss, '.world-map-container .country-tooltip')
    || !str_contains($overrideCss, 'pointer-events: none !important;')
    || !str_contains($overrideCss, '.world-map-container .map-svg .country,')
    || !str_contains($overrideCss, '.world-map-container .map-svg .country.online:hover')
    || !str_contains($overrideCss, 'stroke-width: 0.8px !important;')
    || !str_contains($dashboardTemplate, 'luck-overrides.css?v=20')
    || !str_contains($dashboardTemplate, 'BBbuoBq5-fresh.js?v=60')
    || !str_contains($dashboardTemplate, 'i18n-v18.js?v=60')) {
    fwrite(STDERR, "Luck world-map flicker guard or cache version is missing.\n");
    exit(1);
}

echo "Luck auth, register, payment, chart, free-plan and invitation patches verified.\n";
