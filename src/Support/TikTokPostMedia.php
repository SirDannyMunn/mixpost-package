<?php

namespace Inovector\Mixpost\Support;

use Illuminate\Support\Collection;

class TikTokPostMedia
{
    public static function isPhotoPost(Collection $media): bool
    {
        return $media->isNotEmpty() && $media->every(fn ($item) => in_array($item->mime_type, ['image/jpeg', 'image/webp', 'image/png'], true));
    }

    public static function error(Collection $media): ?string
    {
        if ($media->contains(fn ($item) => $item->disk === 'external_media')) {
            return 'Upload the original media files before scheduling on TikTok.';
        }
        if (self::isPhotoPost($media)) {
            if ($media->count() > 35) return 'TikTok supports at most 35 photos per post.';
            if ($media->contains(fn ($item) => $item->size > 20 * 1024 * 1024)) return 'Each TikTok photo must be no larger than 20 MB.';
            return null;
        }
        if ($media->count() === 1 && $media->first()->isVideo()) return null;
        return 'Choose 1–35 photos or one video for TikTok. Photos and videos cannot be mixed.';
    }
}
