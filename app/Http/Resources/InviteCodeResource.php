<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InviteCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            "id" => $this['id'],
            "user_id" => $this['user_id'],
            "code" => $this['code'],
            "pv"    => $this['pv'],
            "status" => (int) $this['status'],
            "created_at" => $this['created_at'],
            "updated_at" => $this['updated_at']
        ];
        if(!config('hidden_features.enable_exposed_user_count_fix')) $data['user_id']= $this['user_id'];
        return $data;
    }
}
