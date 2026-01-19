<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Composer\InstalledVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Http\Requests\ClearSystemLog;
use Inovector\Mixpost\Http\Requests\DownloadSystemLog;
use Inovector\Mixpost\Support\HorizonStatus;
use Inovector\Mixpost\Support\SystemLogs;
use Inovector\Mixpost\Util;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemApiController extends Controller
{
    /**
     * Get system status.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'health' => [
                'env' => App::environment(),
                'debug' => Config::get('app.debug'),
                'horizon_status' => resolve(HorizonStatus::class)->get(),
                'has_queue_connection' => Config::get('queue.connections.mixpost-redis') && !empty(Config::get('queue.connections.mixpost-redis')),
                'last_scheduled_run' => $this->getLastScheduleRun(),
            ],
            'tech' => [
                'cache_driver' => Config::get('cache.default'),
                'base_path' => base_path(),
                'disk' => Config::get('mixpost.disk'),
                'log_channel' => Config::get('mixpost.log_channel') ? Config::get('mixpost.log_channel') : Config::get('logging.default'),
                'user_agent' => $request->userAgent(),
                'ffmpeg_status' => Util::isFFmpegInstalled() ? 'Installed' : 'Not Installed',
                'versions' => [
                    'php' => PHP_VERSION,
                    'laravel' => App::version(),
                    'horizon' => InstalledVersions::getVersion('laravel/horizon'),
                    'mysql' => $this->mysqlVersion(),
                    'mixpost' => InstalledVersions::getVersion('inovector/mixpost'),
                ],
            ],
        ]);
    }

    /**
     * Get system logs.
     */
    public function logs(): JsonResponse
    {
        $systemLogs = new SystemLogs();

        return response()->json([
            'logs' => $systemLogs->logs(),
        ]);
    }

    /**
     * Download a log file.
     */
    public function downloadLog(DownloadSystemLog $systemLog): StreamedResponse
    {
        $filePath = $systemLog->handle();

        $headers = [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="' . $systemLog->input('filename') . '"',
        ];

        return new StreamedResponse(function () use ($filePath) {
            $handle = fopen($filePath, 'r');
            fpassthru($handle);
            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Clear a log file.
     */
    public function clearLog(ClearSystemLog $clearSystemLog): JsonResponse
    {
        $clearSystemLog->handle();

        return response()->json([
            'message' => 'Log file cleared successfully',
        ]);
    }

    protected function getLastScheduleRun(): array
    {
        $lastScheduleRun = Cache::get('mixpost-last-schedule-run');

        if (!$lastScheduleRun) {
            return [
                'variant' => 'error',
                'message' => 'It never started',
            ];
        }

        $diff = (int) abs(Carbon::now('UTC')->diffInMinutes($lastScheduleRun));

        if ($diff < 10) {
            return [
                'variant' => 'success',
                'message' => "Ran $diff minute(s) ago",
            ];
        }

        return [
            'variant' => 'warning',
            'message' => "Ran $diff minute(s) ago",
        ];
    }

    protected function mysqlVersion(): string
    {
        if (!Util::isMysqlDatabase()) {
            return '';
        }

        $results = DB::select('select version() as version');

        return (string) $results[0]->version;
    }
}
