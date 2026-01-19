<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Inovector\Mixpost\Facades\Settings;
use Inovector\Mixpost\Http\Requests\SaveSettings;
use Inovector\Mixpost\Support\TimezoneList;

class SettingsApiController extends Controller
{
    /**
     * Get all settings.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => Settings::all(),
            'timezone_list' => (new TimezoneList())->splitGroup()->list(),
        ]);
    }

    /**
     * Update settings.
     */
    public function update(SaveSettings $saveSettings): JsonResponse
    {
        $saveSettings->handle();

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => Settings::all(),
        ]);
    }
}
