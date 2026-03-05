<?php

namespace Inovector\Mixpost;

use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

class Schedule
{
    public static function register(LaravelSchedule $schedule): void
    {
        if (config('mixpost.schedule.run_scheduled_posts_enabled', true)) {
            $schedule->command('mixpost:run-scheduled-posts')->everyMinute();
        }

        if (config('mixpost.schedule.refresh_access_tokens_enabled', true)) {
            $schedule->command('mixpost:refresh-access-tokens')->daily();
        }

        if (config('mixpost.schedule.import_account_data_enabled', true)) {
            $schedule->command('mixpost:import-account-data')->everyTwoHours();
        }

        if (config('mixpost.schedule.import_account_audience_enabled', true)) {
            $schedule->command('mixpost:import-account-audience')->everyThreeHours();
        }

        if (config('mixpost.schedule.process_metrics_enabled', true)) {
            $schedule->command('mixpost:process-metrics')->everyThreeHours();
        }

        if (config('mixpost.schedule.delete_old_data_enabled', true)) {
            $schedule->command('mixpost:delete-old-data')->daily();
        }

        if (config('mixpost.schedule.prune_temporary_directory_enabled', true)) {
            $schedule->command('mixpost:prune-temporary-directory')->hourly();
        }
    }
}
