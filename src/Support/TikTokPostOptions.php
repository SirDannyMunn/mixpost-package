<?php

namespace Inovector\Mixpost\Support;

class TikTokPostOptions
{
    public static function rules(): array
    {
        $prefix = 'versions.*.options';
        $rules = [$prefix => ['sometimes', 'nullable', 'array'], "$prefix.tiktok" => ['sometimes', 'array'],
            "$prefix.tiktok.mode" => ['required_with:versions.*.options.tiktok', 'in:direct,draft'],
            "$prefix.tiktok.privacy_level" => ['nullable', 'in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,FOLLOWER_OF_CREATOR,SELF_ONLY']];
        foreach (['allow_comment', 'allow_duet', 'allow_stitch', 'commercial_content', 'brand_organic', 'brand_content', 'consent'] as $key) {
            $rules["$prefix.tiktok.$key"] = ['sometimes', 'boolean'];
        }
        return $rules;
    }

    public static function error(array $options): ?string
    {
        if (($options['consent'] ?? false) !== true && ($options['consent'] ?? false) !== 1) return 'Review and approve this content in the TikTok composer before scheduling.';
        if (!in_array($options['mode'] ?? null, ['direct', 'draft'], true)) return 'Choose a TikTok delivery mode.';
        if ($options['mode'] === 'draft') return null;
        if (!in_array($options['privacy_level'] ?? '', ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'], true)) return 'Choose who can view this TikTok post.';
        if (!empty($options['commercial_content']) && empty($options['brand_organic']) && empty($options['brand_content'])) return 'Choose a commercial content disclosure.';
        if (!empty($options['brand_content']) && ($options['privacy_level'] === 'SELF_ONLY' || empty($options['commercial_content']))) return 'Paid partnership content requires disclosure and cannot be private.';
        return null;
    }
}
