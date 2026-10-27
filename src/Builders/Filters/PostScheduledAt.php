<?php

namespace Inovector\Mixpost\Builders\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Inovector\Mixpost\Contracts\Filter;
use Inovector\Mixpost\Enums\PostStatus;
use Inovector\Mixpost\Facades\Settings;

class PostScheduledAt implements Filter
{
    public static function apply(Builder $builder, $value): Builder
    {
        $date = Carbon::parse($value['date'], Settings::get('timezone') ?: 'UTC');
        [$start, $end] = match ($value['calendar_type']) {
            'month' => [$date->copy()->startOfMonth()->subDays(10), $date->copy()->endOfMonth()->addDays(10)],
            'week' => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            default => [null, null],
        };
        if (!$start) return $builder;

        $start = $start->startOfDay()->utc();
        $end = $end->endOfDay()->utc();
        return $builder->where(function (Builder $query) use ($start, $end) {
            $query->whereBetween('scheduled_at', [$start, $end])
                ->orWhere(function (Builder $drafts) use ($start, $end) {
                    $drafts->where('status', PostStatus::DRAFT->value)
                        ->whereNull('scheduled_at')
                        ->whereBetween('created_at', [$start, $end]);
                });
        });
    }
}
