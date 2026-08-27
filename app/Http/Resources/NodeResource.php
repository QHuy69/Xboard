<?php

namespace App\Http\Resources;

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
            'rate' => $this['rate'],
            'tags' => $this['tags'],
            'is_online' => $this['is_online'],
            'cache_key' => $this['cache_key'],
            'last_check_at' => $this['last_check_at']
        ];
    }
}
