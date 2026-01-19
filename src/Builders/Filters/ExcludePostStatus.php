<?php

namespace Inovector\Mixpost\Builders\Filters;

use Illuminate\Database\Eloquent\Builder;
use Inovector\Mixpost\Contracts\Filter;
use Inovector\Mixpost\Enums\PostStatus as PostStatusEnum;

class ExcludePostStatus implements Filter
{
    public static function apply(Builder $builder, $value): Builder
    {
        // Normalize to lowercase to support both 'SCHEDULED' and 'scheduled'
        $normalizedValue = strtolower($value);
        
        $status = match ($normalizedValue) {
            'draft' => PostStatusEnum::DRAFT->value,
            'scheduled' => PostStatusEnum::SCHEDULED->value,
            'published' => PostStatusEnum::PUBLISHED->value,
            'failed' => PostStatusEnum::FAILED->value,
            default => null
        };

        if ($status === null) {
            return $builder;
        }

        return $builder->whereNot('status', $status);
    }
}
