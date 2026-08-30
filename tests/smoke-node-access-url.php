<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Resources\NodeResource;
use App\Services\LuckThemeAssetPatcher;
use Illuminate\Http\Request;

$node = [
    'id' => 1,
    'type' => 'shadowsocks',
    'name' => 'Vietnam Anti-Lag',
    'host' => '1-antilag-hcm-vn.zaoguang-vpn.com',
    'port' => 8888,
    'server_port' => 8888,
    'password' => 'user-secret',
    'protocol_settings' => ['cipher' => 'aes-256-gcm'],
    'rate' => 1,
    'tags' => [],
    'is_online' => 1,
    'cache_key' => 'node-1',
    'last_check_at' => 1,
];

$data = (new NodeResource($node))->toArray(Request::create('/'));
$credentials = rtrim(strtr(base64_encode('aes-256-gcm:user-secret'), '+/', '-_'), '=');
$expected = "ss://{$credentials}@1-antilag-hcm-vn.zaoguang-vpn.com:8888#Vietnam%20Anti-Lag";

if (($data['access_url'] ?? null) !== $expected) {
    fwrite(STDERR, "Shadowsocks access URL smoke test failed.\n");
    exit(1);
}
if (($data['outline_compatible'] ?? null) !== true) {
    fwrite(STDERR, "Outline cipher compatibility smoke test failed.\n");
    exit(1);
}

$node2022 = $node;
$node2022['protocol_settings']['cipher'] = '2022-blake3-aes-256-gcm';
$node2022['password'] = 'server-key:user-key';
$data2022 = (new NodeResource($node2022))->toArray(Request::create('/'));
if (($data2022['outline_compatible'] ?? null) !== false) {
    fwrite(STDERR, "Shadowsocks 2022 must not be reported as Outline-compatible.\n");
    exit(1);
}

$routeFile = getenv('XBOARD_ROUTES_FILE') ?: dirname(__DIR__) . '/routes/web.php';
$routeSource = file_get_contents($routeFile);
if (!str_contains($routeSource, 'return server.access_url;')
    || !str_contains($routeSource, 'LuckThemeAssetPatcher::rewriteNodeAssetImport($assetContents)')
    || !str_contains($routeSource, 'LuckThemeAssetPatcher::nodeAccessAssetName($runtimeFile)')
    || !str_contains($routeSource, "str_starts_with(\$runtimeFile, 'assets/BBbuoBq5')")) {
    fwrite(STDERR, "Luck node chunk patch smoke test failed.\n");
    exit(1);
}

$nodeImportCases = [
    './oPGsis9D-v8-v3-fresh.js' => './oPGsis9D-v8-v3-fresh-access-v2.js',
    './oPGsis9D-v8-v3-fresh.js?v=53' => './oPGsis9D-v8-v3-fresh-access-v2.js',
    './oPGsis9D-v8-v3-fresh-access.js' => './oPGsis9D-v8-v3-fresh-access-v2.js',
    './oPGsis9D-v8-v3-fresh-access-access.js' => './oPGsis9D-v8-v3-fresh-access-v2.js',
    'assets/oPGsis9D-v8-v3-fresh-access-v2.js' => 'assets/oPGsis9D-v8-v3-fresh-access-v2.js',
];
foreach ($nodeImportCases as $entryImport => $expectedImport) {
    $patchedImport = LuckThemeAssetPatcher::rewriteNodeAssetImport($entryImport);
    $patchedTwice = LuckThemeAssetPatcher::rewriteNodeAssetImport($patchedImport);
    if ($patchedImport !== $expectedImport
        || $patchedTwice !== $expectedImport
        || str_contains($patchedImport, '-access-access')) {
        fwrite(STDERR, "Idempotent Luck node chunk rewrite failed for {$entryImport}.\n");
        exit(1);
    }
}

foreach ([
    'oPGsis9D-v8-v3-fresh.js',
    'oPGsis9D-v8-v3-fresh-access.js',
    'oPGsis9D-v8-v3-fresh-access-access-v2.js',
] as $assetName) {
    if (LuckThemeAssetPatcher::nodeAccessAssetName($assetName) !== 'oPGsis9D-v8-v3-fresh-access-v2.js') {
        fwrite(STDERR, "Luck node output filename normalization failed for {$assetName}.\n");
        exit(1);
    }
}

echo "Outline-compatible Shadowsocks access URL verified.\n";
