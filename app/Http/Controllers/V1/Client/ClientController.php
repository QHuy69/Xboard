<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Protocols\General;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Protocol prefix mapping for server names
     */
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [
            1 => '[Hy]',
            2 => '[Hy2]'
        ],
        'vless' => '[vless]',
        'shadowsocks' => '[ss]',
        'vmess' => '[vmess]',
        'trojan' => '[trojan]',
        'tuic' => '[tuic]',
        'socks' => '[socks]',
        'anytls' => '[anytls]'
    ];


    public function subscribe(Request $request)
    {
        HookManager::call('client.subscribe.before');
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $userService = new UserService();

        if (!$userService->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            return response('', 403, ['Content-Type' => 'text/plain']);
        }

        return $this->doSubscribe($request, $user);
    }

    public function doSubscribe(Request $request, $user, $servers = null)
    {
        if ($servers === null) {
            $servers = ServerService::getAvailableServers($user);
            $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
        }

        $clientInfo = $this->getClientInfo($request);

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        $protocolClassName = app('protocols.manager')->matchProtocolClassName($clientInfo['flag'])
            ?? General::class;

        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );

        $this->setSubscribeInfoToServers($serversFiltered, $user, count($servers) - count($serversFiltered), $request);
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        // Instantiate the protocol class with filtered servers and client info
        $protocolInstance = app()->make($protocolClassName, [
            'user' => $user,
            'servers' => $serversFiltered,
            'clientName' => $clientInfo['name'] ?? null,
            'clientVersion' => $clientInfo['version'] ?? null,
            'userAgent' => $clientInfo['flag'] ?? null
        ]);

        return $protocolInstance->handle();
    }

    /**
     * Parses the input string for requested server types.
     */
    private function parseRequestedTypes(?string $typeInputString): array
    {
        if (blank($typeInputString) || $typeInputString === 'all') {
            return Server::VALID_TYPES;
        }

        $requested = collect(preg_split('/[|,｜]+/', $typeInputString))
            ->map(fn($type) => trim($type))
            ->filter() // Remove empty strings that might result from multiple delimiters
            ->all();

        return array_values(array_intersect($requested, Server::VALID_TYPES));
    }

    /**
     * Parses the input string for filter keywords.
     */
    private function parseFilterKeywords(?string $filterInputString): ?array
    {
        if (blank($filterInputString) || mb_strlen($filterInputString) > 20) {
            return null;
        }

        return collect(preg_split('/[|,｜]+/', $filterInputString))
            ->map(fn($keyword) => trim($keyword))
            ->filter() // Remove empty strings
            ->all();
    }

    /**
     * Filters servers based on allowed types and keywords.
     */
    private function filterServers(array $servers, array $allowedTypes, ?array $filterKeywords): array
    {
        return collect($servers)->filter(function ($server) use ($allowedTypes, $filterKeywords) {
            // Condition 1: Server type must be in the list of allowed types
            if ($allowedTypes && !in_array($server['type'], $allowedTypes)) {
                return false; // Filter out (don't keep)
            }

            // Condition 2: If filterKeywords are provided, at least one keyword must match
            if (!empty($filterKeywords)) { // Check if $filterKeywords is not empty
                $keywordMatch = collect($filterKeywords)->contains(function ($keyword) use ($server) {
                    return stripos($server['name'], $keyword) !== false
                        || in_array($keyword, $server['tags'] ?? []);
                });
                if (!$keywordMatch) {
                    return false; // Filter out if no keywords match
                }
            }
            // Keep the server if its type is allowed AND (no filter keywords OR at least one keyword matched)
            return true;
        })->values()->all();
    }

    private function getClientInfo(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));

        $clientName = null;
        $clientVersion = null;

        if (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $flag, $matches)) {
            $potentialName = strtolower($matches[1]);
            $clientVersion = preg_replace('/^v/', '', $matches[2]);

            if (in_array($potentialName, app('protocols.flags'))) {
                $clientName = $potentialName;
            }
        }

        if (!$clientName) {
            $flags = collect(app('protocols.flags'))->sortByDesc(fn($f) => strlen($f))->values()->all();
            foreach ($flags as $name) {
                if (stripos($flag, $name) !== false) {
                    $clientName = $name;
                    if (!$clientVersion) {
                        $pattern = '/' . preg_quote($name, '/') . '[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/i';
                        if (preg_match($pattern, $flag, $vMatches)) {
                            $clientVersion = preg_replace('/^v/', '', $vMatches[1]);
                        }
                    }
                    break;
                }
            }
        }

        if (!$clientVersion) {
            if (preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $flag, $matches)) {
                $clientVersion = $matches[1];
            }
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion
        ];
    }

    private const SUBSCRIBE_INFO_LABELS = [
        'zh-CN' => [
            'remaining' => '剩余流量：%s', 'expiry' => '套餐到期：%s',
            'reset' => '距离下次重置剩余：%d 天', 'website' => '官网：%s', 'never' => '长期有效',
        ],
        'zh-TW' => [
            'remaining' => '剩餘流量：%s', 'expiry' => '方案到期：%s',
            'reset' => '距離下次重置剩餘：%d 天', 'website' => '官網：%s', 'never' => '長期有效',
        ],
        'en-US' => [
            'remaining' => 'Remaining traffic: %s', 'expiry' => 'Plan expires: %s',
            'reset' => 'Next reset in: %d days', 'website' => 'Website: %s', 'never' => 'Never expires',
        ],
        'vi-VN' => [
            'remaining' => 'Lưu lượng còn lại: %s', 'expiry' => 'Gói hết hạn: %s',
            'reset' => 'Còn %d ngày đến lần đặt lại tiếp theo', 'website' => 'Website: %s', 'never' => 'Không bao giờ hết hạn',
        ],
        'ja-JP' => [
            'remaining' => '残り通信量：%s', 'expiry' => 'プラン有効期限：%s',
            'reset' => '次回リセットまで：%d日', 'website' => '公式サイト：%s', 'never' => '無期限',
        ],
        'ko-KR' => [
            'remaining' => '남은 트래픽: %s', 'expiry' => '요금제 만료일: %s',
            'reset' => '다음 초기화까지: %d일', 'website' => '웹사이트: %s', 'never' => '만료되지 않음',
        ],
        'fa-IR' => [
            'remaining' => 'حجم باقی‌مانده: %s', 'expiry' => 'انقضای طرح: %s',
            'reset' => 'تا بازنشانی بعدی: %d روز', 'website' => 'وب‌سایت: %s', 'never' => 'بدون انقضا',
        ],
        'ru-RU' => [
            'remaining' => 'Остаток трафика: %s', 'expiry' => 'Срок действия тарифа: %s',
            'reset' => 'До следующего сброса: %d дн.', 'website' => 'Сайт: %s', 'never' => 'Бессрочно',
        ],
    ];

    private function subscribeInfoLocale(Request $request, $user = null): string
    {
        $supported = array_keys(self::SUBSCRIBE_INFO_LABELS);
        $stored = str_replace('_', '-', (string) data_get($user, 'locale', ''));
        if (in_array($stored, $supported, true)) {
            return $stored;
        }
        $cookie = str_replace('_', '-', (string) $request->cookie('luck_locale', ''));
        if (in_array($cookie, $supported, true)) {
            return $cookie;
        }
        foreach ($request->getLanguages() as $language) {
            $language = str_replace('_', '-', (string) $language);
            if (in_array($language, $supported, true)) {
                return $language;
            }
            $base = strtolower(explode('-', $language)[0]);
            foreach ($supported as $candidate) {
                if (strtolower(explode('-', $candidate)[0]) === $base) {
                    return $candidate;
                }
            }
        }
        return 'en-US';
    }

    private function setSubscribeInfoToServers(&$servers, $user, $rejectServerCount = 0, ?Request $request = null)
    {
        if (!isset($servers[0]))
            return;
        if ($rejectServerCount > 0) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "过滤掉{$rejectServerCount}条线路",
            ]));
        }
        if (!(int) admin_setting('show_info_to_server_enable', 0))
            return;
        $labels = self::SUBSCRIBE_INFO_LABELS[$this->subscribeInfoLocale($request ?? request(), $user)];
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : $labels['never'];
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        $website = trim((string) admin_setting('app_url', ''));
        if ($website !== '') {
            $website = parse_url($website, PHP_URL_HOST) ?: $website;
            array_unshift($servers, array_merge($servers[0], [
                'name' => sprintf($labels['website'], $website),
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => sprintf($labels['expiry'], $expiredDate),
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => sprintf($labels['reset'], $resetDay),
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => sprintf($labels['remaining'], $remainingTraffic),
        ]));
    }

    private function addPrefixToServerName(array $servers): array
    {
        if (!admin_setting('show_protocol_to_server_enable', false)) {
            return $servers;
        }
        return collect($servers)
            ->map(function (array $server): array {
                $server['name'] = $this->getPrefixedServerName($server);
                return $server;
            })
            ->all();
    }

    private function getPrefixedServerName(array $server): string
    {
        $type = $server['type'] ?? '';
        if (!isset(self::PROTOCOL_PREFIXES[$type])) {
            return $server['name'] ?? '';
        }
        $prefix = is_array(self::PROTOCOL_PREFIXES[$type])
            ? self::PROTOCOL_PREFIXES[$type][$server['protocol_settings']['version'] ?? 1] ?? ''
            : self::PROTOCOL_PREFIXES[$type];
        return $prefix . ($server['name'] ?? '');
    }
}
