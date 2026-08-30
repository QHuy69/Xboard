<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Redis;

/**
 * Read the short-lived online-IP state for one authenticated user.
 *
 * The node core writes `nodeId:ip => lastSeenTimestamp` fields to the
 * `user_devices:{userId}` hash.  This reader deliberately accepts a concrete
 * user id and performs one HGETALL against that exact hash; it never scans
 * Redis and it never persists IP history.
 */
final class UserDeviceReadService
{
    private const REDIS_PREFIX = 'user_devices:';
    private const TTL_SECONDS = 300;

    /**
     * @return list<array{
     *     ip: string,
     *     node_id: int,
     *     node_name: string,
     *     type: string,
     *     last_seen_at: int,
     *     age_seconds: int
     * }>
     */
    public function currentForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $entries = $this->parseFreshEntries(
            Redis::hgetall(self::REDIS_PREFIX . $userId),
            time()
        );
        if ($entries === []) {
            return [];
        }

        $nodeIds = array_values(array_unique(array_column($entries, 'node_id')));
        $nodes = Server::query()
            ->select(['id', 'name', 'type'])
            ->whereIn('id', $nodeIds)
            ->where('enabled', true)
            ->get()
            ->keyBy('id');

        $current = [];
        foreach ($entries as $entry) {
            $node = $nodes->get($entry['node_id']);
            if ($node === null) {
                continue;
            }

            $current[] = [
                'ip' => $entry['ip'],
                'node_id' => (int) $node->id,
                'node_name' => (string) $node->name,
                'type' => (string) $node->type,
                'last_seen_at' => $entry['last_seen_at'],
                'age_seconds' => $entry['age_seconds'],
            ];
        }

        usort($current, static function (array $left, array $right): int {
            return $right['last_seen_at'] <=> $left['last_seen_at']
                ?: strnatcasecmp($left['ip'], $right['ip']);
        });

        return $current;
    }

    /**
     * Normalize fresh Redis fields and keep only the latest node for each IP.
     * An IP can briefly appear on two nodes while a client is switching; one
     * row per IP keeps the dashboard count aligned with the device-limit logic.
     *
     * @param array<string, int|string> $fields
     * @return list<array{ip: string, node_id: int, last_seen_at: int, age_seconds: int}>
     */
    private function parseFreshEntries(array $fields, int $now): array
    {
        $latestByIp = [];

        foreach ($fields as $field => $lastSeenValue) {
            $separator = strpos((string) $field, ':');
            if ($separator === false) {
                continue;
            }

            $nodeId = (int) substr((string) $field, 0, $separator);
            $ip = substr((string) $field, $separator + 1);
            $lastSeenAt = filter_var($lastSeenValue, FILTER_VALIDATE_INT);
            if ($nodeId <= 0
                || $lastSeenAt === false
                || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            // A small clock skew must not turn a current device into a stale
            // one. Future timestamps are displayed as age zero.
            $ageSeconds = max(0, $now - (int) $lastSeenAt);
            if ($ageSeconds > self::TTL_SECONDS) {
                continue;
            }

            if (isset($latestByIp[$ip])
                && $latestByIp[$ip]['last_seen_at'] >= (int) $lastSeenAt) {
                continue;
            }

            $latestByIp[$ip] = [
                'ip' => $ip,
                'node_id' => $nodeId,
                'last_seen_at' => (int) $lastSeenAt,
                'age_seconds' => $ageSeconds,
            ];
        }

        return array_values($latestByIp);
    }
}
