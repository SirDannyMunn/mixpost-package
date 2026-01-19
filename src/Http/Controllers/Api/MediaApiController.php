<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Inovector\Mixpost\Enums\ServiceGroup;
use Inovector\Mixpost\Facades\ServiceManager;
use Inovector\Mixpost\Http\Requests\DeleteMedia;

class MediaApiController extends Controller
{
    /**
     * List media library configuration.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'is_configured_service' => ServiceManager::isActive(
                ServiceManager::services()->group(ServiceGroup::MEDIA)->getNames()
            ),
        ]);
    }

    /**
     * Delete media files.
     */
    public function destroy(DeleteMedia $deleteMediaFiles): HttpResponse
    {
        $deleteMediaFiles->handle();

        return response()->noContent();
    }
}
