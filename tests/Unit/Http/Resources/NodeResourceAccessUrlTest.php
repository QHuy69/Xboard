<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\NodeResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class NodeResourceAccessUrlTest extends TestCase
{
    public function test_shadowsocks_node_contains_an_outline_compatible_access_url(): void
    {
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

        $this->assertSame(
            "ss://{$credentials}@1-antilag-hcm-vn.zaoguang-vpn.com:8888#Vietnam%20Anti-Lag",
            $data['access_url']
        );
        $this->assertTrue($data['outline_compatible']);
        $this->assertStringNotContainsString('placeholder', $data['access_url']);
    }

    public function test_shadowsocks_access_url_wraps_ipv6_hosts(): void
    {
        $node = [
            'id' => 2,
            'type' => 'shadowsocks',
            'name' => 'IPv6 node',
            'host' => '2001:db8::1',
            'port' => 443,
            'password' => 'secret',
            'protocol_settings' => ['cipher' => 'chacha20-ietf-poly1305'],
            'rate' => 1,
            'tags' => [],
            'is_online' => 1,
            'cache_key' => 'node-2',
            'last_check_at' => 1,
        ];

        $data = (new NodeResource($node))->toArray(Request::create('/'));

        $this->assertStringContainsString('@[2001:db8::1]:443#IPv6%20node', $data['access_url']);
        $this->assertTrue($data['outline_compatible']);
    }

    public function test_shadowsocks_2022_url_is_valid_but_reported_as_unsupported_by_outline(): void
    {
        $node = [
            'id' => 3,
            'type' => 'shadowsocks',
            'name' => 'SS 2022 node',
            'host' => 'ss2022.example.com',
            'port' => 443,
            'password' => 'server-key:user-key',
            'protocol_settings' => ['cipher' => '2022-blake3-aes-256-gcm'],
            'rate' => 1,
            'tags' => [],
            'is_online' => 1,
            'cache_key' => 'node-3',
            'last_check_at' => 1,
        ];

        $data = (new NodeResource($node))->toArray(Request::create('/'));

        $this->assertStringStartsWith('ss://', $data['access_url']);
        $this->assertStringContainsString('@ss2022.example.com:443#SS%202022%20node', $data['access_url']);
        $this->assertFalse($data['outline_compatible']);
    }
}
