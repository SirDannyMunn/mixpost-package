<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Models\Account;

class DashboardApiController extends Controller
{
    /**
     * Get dashboard data.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'accounts' => AccountResource::collection(Account::oldest()->get())->resolve(),
        ]);
    }
}
