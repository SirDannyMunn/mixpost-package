<?php

/*
|--------------------------------------------------------------------------
| Social Scheduling API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the MixpostServiceProvider within a group that
| is assigned the "api" middleware group. They handle stateless API requests
| for OAuth handoff and entity selection flows.
|
| All routes return JSON responses (no Inertia/SSR).
|
*/

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

// API Controllers (JSON responses)
use Inovector\Mixpost\Http\Controllers\Api\AccountEntitiesApiController;
use Inovector\Mixpost\Http\Controllers\Api\AccountsApiController;
use Inovector\Mixpost\Http\Controllers\Api\CalendarApiController;
use Inovector\Mixpost\Http\Controllers\Api\DashboardApiController;
use Inovector\Mixpost\Http\Controllers\Api\MediaApiController;
use Inovector\Mixpost\Http\Controllers\Api\OAuthHandoffController;
use Inovector\Mixpost\Http\Controllers\Api\PostsApiController;
use Inovector\Mixpost\Http\Controllers\Api\ProfileApiController;
use Inovector\Mixpost\Http\Controllers\Api\ServicesApiController;
use Inovector\Mixpost\Http\Controllers\Api\SettingsApiController;
use Inovector\Mixpost\Http\Controllers\Api\SystemApiController;
use Inovector\Mixpost\Http\Controllers\Api\TagsApiController;

// Original controllers for specialized functionality
use Inovector\Mixpost\Http\Controllers\AddAccountController;
use Inovector\Mixpost\Http\Controllers\AuthenticatedController;
use Inovector\Mixpost\Http\Controllers\CallbackSocialProviderController;
use Inovector\Mixpost\Http\Controllers\CreateMastodonAppController;
use Inovector\Mixpost\Http\Controllers\DeletePostsController;
use Inovector\Mixpost\Http\Controllers\DuplicatePostController;
use Inovector\Mixpost\Http\Controllers\MediaDownloadExternalController;
use Inovector\Mixpost\Http\Controllers\MediaFetchGifsController;
use Inovector\Mixpost\Http\Controllers\MediaFetchStockController;
use Inovector\Mixpost\Http\Controllers\MediaFetchUploadsController;
use Inovector\Mixpost\Http\Controllers\MediaUploadFileController;
use Inovector\Mixpost\Http\Controllers\ReportsController;
use Inovector\Mixpost\Http\Controllers\SchedulePostController;
use Inovector\Mixpost\Http\Controllers\UpdateAuthUserController;
use Inovector\Mixpost\Http\Controllers\UpdateAuthUserPasswordController;


Route::prefix('social')->name('social.api.')->group(function () {
    // OAuth handoff token exchange (for Chrome extension)
    Route::post('oauth/exchange', [OAuthHandoffController::class, 'exchange'])
        ->name('oauth.exchange');

    // OAuth entity selection flow (for Facebook Pages, etc.)
    Route::post('oauth/entities', [OAuthHandoffController::class, 'getEntities'])
        ->name('oauth.entities');
    
    Route::post('oauth/select-entity', [OAuthHandoffController::class, 'selectEntity'])
        ->name('oauth.selectEntity');
});


Route::middleware('auth:sanctum')
    ->prefix('social')
    ->name('social.')
    ->group(function () {
        Route::get('/', DashboardApiController::class)->name('dashboard');
        Route::get('reports', ReportsController::class)->name('reports');

        Route::prefix('accounts')->name('accounts.')->group(function () {
            Route::get('/', [AccountsApiController::class, 'index'])->name('index');
            Route::post('add/{provider}', AddAccountController::class)->name('add');
            Route::put('update/{account}', [AccountsApiController::class, 'update'])->name('update');
            Route::delete('{account}', [AccountsApiController::class, 'destroy'])->name('delete');

            Route::prefix('entities')->name('entities.')->group(function () {
                Route::get('{provider}', [AccountEntitiesApiController::class, 'index'])->name('index');
                Route::post('{provider}', [AccountEntitiesApiController::class, 'store'])->name('store');
            });
        });

        Route::prefix('posts')->name('posts.')->group(function () {
            Route::get('/', [PostsApiController::class, 'index'])->name('index');
            Route::get('create/{schedule_at?}', [PostsApiController::class, 'create'])
                ->name('create')
                ->where('schedule_at', '^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01]) (0\d|1\d|2[0-3]):([0-5]\d)$');
            Route::post('store', [PostsApiController::class, 'store'])->name('store');
            Route::get('{post}', [PostsApiController::class, 'show'])->name('show');
            Route::put('{post}', [PostsApiController::class, 'update'])->name('update');
            Route::delete('{post}', [PostsApiController::class, 'destroy'])->name('delete');

            Route::post('schedule/{post}', SchedulePostController::class)->name('schedule');
            Route::post('duplicate/{post}', DuplicatePostController::class)->name('duplicate');
            Route::delete('/', DeletePostsController::class)->name('multipleDelete');
        });

        Route::get('calendar/{date?}/{type?}', [CalendarApiController::class, 'index'])
            ->name('calendar')
            ->where('date', '^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$')
            ->where('type', '^(?:month|week)$');

        Route::prefix('media')->name('media.')->group(function () {
            Route::get('/', [MediaApiController::class, 'index'])->name('index');
            Route::delete('/', [MediaApiController::class, 'destroy'])->name('delete');
            Route::get('fetch/uploaded', MediaFetchUploadsController::class)->name('fetchUploads');
            Route::get('fetch/stock', MediaFetchStockController::class)->name('fetchStock');
            Route::get('fetch/gifs', MediaFetchGifsController::class)->name('fetchGifs');
            Route::post('download', MediaDownloadExternalController::class)->name('download');
            Route::post('upload', MediaUploadFileController::class)->name('upload');
        });

        Route::prefix('tags')->name('tags.')->group(function () {
            Route::get('/', [TagsApiController::class, 'index'])->name('index');
            Route::post('/', [TagsApiController::class, 'store'])->name('store');
            Route::put('{tag}', [TagsApiController::class, 'update'])->name('update');
            Route::delete('{tag}', [TagsApiController::class, 'destroy'])->name('delete');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingsApiController::class, 'index'])->name('index');
            Route::put('/', [SettingsApiController::class, 'update'])->name('update');
        });

        Route::prefix('services')->name('services.')->group(function () {
            Route::get('/', [ServicesApiController::class, 'index'])->name('index');
            Route::put('{service}', [ServicesApiController::class, 'update'])->name('update');

            Route::post('create-mastodon-app', CreateMastodonAppController::class)->name('createMastodonApp');
        });

        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/', [ProfileApiController::class, 'index'])->name('index');
            Route::put('user', UpdateAuthUserController::class)->name('updateUser');
            Route::put('password', UpdateAuthUserPasswordController::class)->name('updatePassword');
        });

        Route::prefix('system')->name('system.')->group(function () {
            Route::get('status', [SystemApiController::class, 'status'])->name('status');

            Route::prefix('logs')->name('logs.')->group(function () {
                Route::get('/', [SystemApiController::class, 'logs'])->name('index');
                Route::get('download', [SystemApiController::class, 'downloadLog'])->name('download');
                Route::delete('clear', [SystemApiController::class, 'clearLog'])->name('clear');
            });
        });

        Route::post('logout', [AuthenticatedController::class, 'destroy'])
            ->name('logout');
    });

// OAuth callback route - MUST be outside auth middleware
// OAuth is an out-of-band flow; the state parameter carries all context
Route::middleware(['web'])
    ->prefix('social')
    ->name('social.')
    ->group(function () {
        Route::get('callback/{provider}', CallbackSocialProviderController::class)->name('callbackSocialProvider');
    });

