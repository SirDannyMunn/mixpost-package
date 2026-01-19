<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inovector\Mixpost\Facades\ServiceManager;
use Inovector\Mixpost\Http\Requests\SaveService;

class ServicesApiController extends Controller
{
    /**
     * List all services.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'services' => ServiceManager::all(),
        ]);
    }

    /**
     * Update a service configuration.
     */
    public function update(SaveService $saveService): JsonResponse
    {
        $saveService->handle();

        return response()->json([
            'message' => 'Service configuration updated successfully',
            'services' => ServiceManager::all(),
        ]);
    }
}
