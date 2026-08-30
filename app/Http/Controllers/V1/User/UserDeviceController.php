<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserDeviceResource;
use App\Services\UserDeviceReadService;
use Illuminate\Http\Request;

final class UserDeviceController extends Controller
{
    public function current(Request $request, UserDeviceReadService $deviceReadService)
    {
        // Never accept a user id from query/body. The authenticated principal
        // is the only scope used to build the Redis key.
        $current = $deviceReadService->currentForUser((int) $request->user()->id);

        return response()->json([
            'data' => [
                'total' => count($current),
                'current' => UserDeviceResource::collection($current)->resolve($request),
            ],
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
