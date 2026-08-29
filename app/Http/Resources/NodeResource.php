<?php

namespace App\Http\Resources;

use App\Models\Server;
use App\Protocols\General;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'type' => $this['type'],
            'version' => $this['version'] ?? null,
            'name' => $this['name'],
            // The Luck theme renders the node address from host/port. Keep
            // these fields in the user API response so it never displays
            // "undefined:undefined" for otherwise valid nodes.
            'host' => $this['host'] ?? null,
            'port' => $this['port'] ?? null,
            'server_port' => $this['server_port'] ?? null,
            // The node dialog must use a real, user-specific access URL. The
            // Luck theme used to assemble placeholder URLs from host/port,
            // which produced invalid Outline keys such as ss://host:port.
            'access_url' => $this->buildAccessUrl(),
            'rate' => $this['rate'],
            'tags' => $this['tags'],
            'is_online' => $this['is_online'],
            'cache_key' => $this['cache_key'],
            'last_check_at' => $this['last_check_at']
        ];
    }

    private function buildAccessUrl(): ?string
    {
        $server = $this->resource;
        $password = data_get($server, 'password');

        if (!is_string($password) || $password === '') {
            return null;
        }

        try {
            $url = match (data_get($server, 'type')) {
                Server::TYPE_SHADOWSOCKS => General::buildShadowsocks($password, $server),
                Server::TYPE_VMESS => General::buildVmess($password, $server),
                Server::TYPE_VLESS => General::buildVless($password, $server),
                Server::TYPE_TROJAN => General::buildTrojan($password, $server),
                Server::TYPE_HYSTERIA => General::buildHysteria($password, $server),
                Server::TYPE_TUIC => General::buildTuic($password, $server),
                Server::TYPE_ANYTLS => General::buildAnyTLS($password, $server),
                Server::TYPE_SOCKS => General::buildSocks($password, $server),
                Server::TYPE_HTTP => General::buildHttp($password, $server),
                default => null,
            };
        } catch (\Throwable) {
            // A malformed optional node must not make the whole node list fail.
            return null;
        }

        return is_string($url) && $url !== '' ? trim($url) : null;
    }
}
