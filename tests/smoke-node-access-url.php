<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Resources\NodeResource;
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

$routeFile = getenv('XBOARD_ROUTES_FILE') ?: dirname(__DIR__) . '/routes/web.php';
$routeSource = file_get_contents($routeFile);
if (!str_contains($routeSource, 'return server.access_url;')
    || !str_contains($routeSource, "preg_replace('/\\.js$/', '-access.js'")) {
    fwrite(STDERR, "Luck node chunk patch smoke test failed.\n");
    exit(1);
}

$entryImport = './oPGsis9D-v8-v3-fresh.js';
$patchedImport = preg_replace_callback(
    '#(?<prefix>\./|assets/)(?<name>oPGsis9D[^"\'?]*\.js)(?:\?v=\d+)?#',
    static function (array $match): string {
        return $match['prefix'] . preg_replace('/\.js$/', '-access.js', $match['name']);
    },
    $entryImport
);
if ($patchedImport !== './oPGsis9D-v8-v3-fresh-access.js') {
    fwrite(STDERR, "Version-independent Luck node chunk rewrite failed.\n");
    exit(1);
}

echo "Outline-compatible Shadowsocks access URL verified.\n";
