<?php

declare(strict_types=1);

/**
 * Give the critical Luck entry/route chunks stable custom fingerprints and
 * patch strings that cannot be translated after rendering (ECharts canvas).
 * The marker checks deliberately fail the image build if upstream changes.
 */

$assetDirectory = $argv[1] ?? '';
if ($assetDirectory === '' || !is_dir($assetDirectory)) {
    fwrite(STDERR, "Luck asset directory is missing.\n");
    exit(1);
}

function findAsset(string $directory, string $marker, string $label): string
{
    $matches = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.js') ?: [] as $path) {
        $contents = file_get_contents($path);
        if ($contents !== false && str_contains($contents, $marker)) {
            $matches[] = $path;
        }
    }
    if (count($matches) !== 1) {
        fwrite(STDERR, sprintf("Expected exactly one %s asset, found %d.\n", $label, count($matches)));
        exit(1);
    }
    return $matches[0];
}

function replaceExact(string $contents, string $search, string $replacement, int $expected, string $label): string
{
    $count = substr_count($contents, $search);
    if ($count !== $expected) {
        fwrite(STDERR, sprintf("Patch marker %s expected %d occurrence(s), found %d.\n", $label, $expected, $count));
        exit(1);
    }
    return str_replace($search, $replacement, $contents);
}

function saveAsset(string $directory, string $name, string $contents): void
{
    $target = $directory . DIRECTORY_SEPARATOR . $name;
    if (file_put_contents($target, $contents) === false) {
        fwrite(STDERR, "Unable to write {$target}.\n");
        exit(1);
    }
}

$entryPath = findAsset($assetDirectory, 'const router = createRouter({', 'entry/router');
$serverPath = findAsset($assetDirectory, '获取节点列表失败', 'server list');
$trafficPath = findAsset($assetDirectory, '流量使用趋势 (最近30天)', 'traffic chart');

$serverName = 'luck-servers-v42.js';
$trafficName = 'luck-traffic-v42.js';
$entryName = 'luck-entry-v42.js';

$server = (string) file_get_contents($serverPath);
$server = replaceExact(
    $server,
    '|| "获取节点列表失败"',
    '|| window.__LUCK_T__("获取节点列表失败")',
    1,
    'server error translation'
);
saveAsset($assetDirectory, $serverName, $server);

$traffic = (string) file_get_contents($trafficPath);
$trafficPatches = [
    ['text: `流量使用趋势 (最近30天)`', 'text: window.__LUCK_T__("流量使用趋势 (最近30天)")', 1, 'chart title'],
    ['data: ["上传流量", "下载流量"]', 'data: [window.__LUCK_T__("上传流量"), window.__LUCK_T__("下载流量")]', 1, 'chart legend'],
    ['name: "流量 (GB)"', 'name: window.__LUCK_T__("流量 (GB)")', 1, 'chart axis'],
    ['name: "上传流量"', 'name: window.__LUCK_T__("上传流量")', 1, 'upload series'],
    ['name: "下载流量"', 'name: window.__LUCK_T__("下载流量")', 1, 'download series'],
];
foreach ($trafficPatches as [$search, $replacement, $expected, $label]) {
    $traffic = replaceExact($traffic, $search, $replacement, $expected, $label);
}
saveAsset($assetDirectory, $trafficName, $traffic);

$entry = (string) file_get_contents($entryPath);
$entry = replaceExact($entry, basename($serverPath), $serverName, 2, 'server chunk fingerprint');
$entry = replaceExact($entry, basename($trafficPath), $trafficName, 2, 'traffic chunk fingerprint');
$entry = replaceExact(
    $entry,
    'const app = createApp(_sfc_main);',
    'window.__LUCK_ROUTER__ = router; router.onError((error) => window.dispatchEvent(new CustomEvent("luck:route-error", { detail: error })));' . "\n" . 'const app = createApp(_sfc_main);',
    1,
    'router error bridge'
);
saveAsset($assetDirectory, $entryName, $entry);

fwrite(STDOUT, "Patched Luck assets: {$entryName}, {$serverName}, {$trafficName}\n");
