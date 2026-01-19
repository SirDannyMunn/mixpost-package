<?php

use Illuminate\Support\Facades\Route;
use Inovector\Mixpost\Http\Controllers\Api\OAuthHandoffController;

/*
|--------------------------------------------------------------------------
| Mixpost API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the MixpostServiceProvider within a group that
| is assigned the "api" middleware group. They handle stateless API requests
| for OAuth handoff and entity selection flows.
|
*/

Route::prefix('mixpost')->name('mixpost.api.')->group(function () {
    // OAuth handoff token exchange (for Chrome extension)
    Route::post('oauth/exchange', [OAuthHandoffController::class, 'exchange'])
        ->name('oauth.exchange');

    // OAuth entity selection flow (for Facebook Pages, etc.)
    Route::post('oauth/entities', [OAuthHandoffController::class, 'getEntities'])
        ->name('oauth.entities');
    
    Route::post('oauth/select-entity', [OAuthHandoffController::class, 'selectEntity'])
        ->name('oauth.selectEntity');
});
