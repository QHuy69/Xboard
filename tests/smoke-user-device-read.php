<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\UserDeviceReadService;

$service = new UserDeviceReadService();
$parse = new ReflectionMethod(UserDeviceReadService::class, 'parseFreshEntries');
$parse->setAccessible(true);
$now = 1_800_000_000;

$rows = $parse->invoke($service, [
    // Same IP on two nodes: keep only the latest report.
    '11:203.0.113.10' => $now - 20,
    '12:203.0.113.10' => $now - 5,
    // IPv6 must retain every colon after the node-id separator.
    '13:2001:db8::cafe' => $now - 60,
    // Small node clock skew is current with a zero age.
    '14:198.51.100.8' => $now + 3,
    // Stale and malformed fields must never reach the API.
    '15:192.0.2.50' => $now - 301,
    'missing-node-separator' => $now,
    '16:not-an-ip' => $now,
    '0:192.0.2.1' => $now,
    '17:192.0.2.2' => 'not-a-timestamp',
], $now);

if (count($rows) !== 3) {
    fwrite(STDERR, 'Fresh-device filter returned an unexpected row count.' . PHP_EOL);
    exit(1);
}

$byIp = [];
foreach ($rows as $row) {
    $byIp[$row['ip']] = $row;
}

if (($byIp['203.0.113.10']['node_id'] ?? null) !== 12
    || ($byIp['203.0.113.10']['age_seconds'] ?? null) !== 5
    || ($byIp['2001:db8::cafe']['node_id'] ?? null) !== 13
    || ($byIp['198.51.100.8']['age_seconds'] ?? null) !== 0
    || isset($byIp['192.0.2.50'])) {
    fwrite(STDERR, 'Fresh-device normalization, deduplication or TTL filtering failed.' . PHP_EOL);
    exit(1);
}

$serviceSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/UserDeviceReadService.php');
$controllerSource = (string) file_get_contents(dirname(__DIR__) . '/app/Http/Controllers/V1/User/UserDeviceController.php');
if (preg_match('/Redis::(?:keys|scan)\s*\(/i', $serviceSource)
    || !str_contains($serviceSource, 'Redis::hgetall(self::REDIS_PREFIX . $userId)')
    || !str_contains($controllerSource, '$request->user()->id')
    || str_contains($controllerSource, "input('user_id")
    || str_contains($controllerSource, "query('user_id")) {
    fwrite(STDERR, 'Current-device endpoint lost its authenticated-user-only Redis scope.' . PHP_EOL);
    exit(1);
}

echo 'User current-device TTL, IP normalization and privacy scope verified.' . PHP_EOL;
