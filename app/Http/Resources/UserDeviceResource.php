<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserDeviceResource extends JsonResource
{
    /**
     * @return array{
     *     ip: string,
     *     node_id: int,
     *     node_name: string,
     *     type: string,
     *     last_seen_at: int,
     *     age_seconds: int
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'ip' => (string) $this->resource['ip'],
            'node_id' => (int) $this->resource['node_id'],
            'node_name' => (string) $this->resource['node_name'],
            'type' => (string) $this->resource['type'],
            'last_seen_at' => (int) $this->resource['last_seen_at'],
            'age_seconds' => (int) $this->resource['age_seconds'],
        ];
    }
}
