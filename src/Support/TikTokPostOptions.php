<?php

namespace Inovector\Mixpost\Support;

use Illuminate\Support\Collection;
use Inovector\Mixpost\Models\Media;

class TikTokPostOptions
{
    public const PHOTO_MIMES = ['image/jpeg', 'image/jpg', 'image/webp'];
    public const MAX_PHOTOS = 35;
    public const MAX_TITLE_UNITS = 90;
    public const MAX_DESCRIPTION_UNITS = 4000;
    public const MAX_PHOTO_BYTES = 20 * 1024 * 1024;

    public static function rules(): array
    {
        $prefix = 'versions.*.options';
        $rules = [$prefix => ['sometimes', 'nullable', 'array'], "$prefix.tiktok" => ['sometimes', 'array'],
            "$prefix.tiktok.mode" => ['required_without:versions.*.options.tiktok.post_mode', 'nullable', 'in:direct,draft'],
            "$prefix.tiktok.post_mode" => ['required_without:versions.*.options.tiktok.mode', 'nullable', 'in:DIRECT_POST,MEDIA_UPLOAD'],
            "$prefix.tiktok.privacy_level" => ['nullable', 'in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,FOLLOWER_OF_CREATOR,SELF_ONLY'],
            "$prefix.tiktok.title" => ['nullable', 'string', 'max:180'],
            "$prefix.tiktok.description" => ['nullable', 'string', 'max:8000'],
            "$prefix.tiktok.photo_cover_index" => ['nullable', 'integer', 'min:0', 'max:34']];
        foreach (['allow_comment', 'allow_duet', 'allow_stitch', 'commercial_content', 'brand_organic', 'brand_content', 'consent', 'auto_add_music', 'is_aigc'] as $key) {
            $rules["$prefix.tiktok.$key"] = ['sometimes', 'boolean'];
        }
        return $rules;
    }

    public static function normalize(array $options): array
    {
        if (empty($options['mode']) && ($options['post_mode'] ?? null) === 'DIRECT_POST') $options['mode'] = 'direct';
        if (empty($options['mode']) && ($options['post_mode'] ?? null) === 'MEDIA_UPLOAD') $options['mode'] = 'draft';
        if (empty($options['post_mode']) && ($options['mode'] ?? null) === 'direct') $options['post_mode'] = 'DIRECT_POST';
        if (empty($options['post_mode']) && ($options['mode'] ?? null) === 'draft') $options['post_mode'] = 'MEDIA_UPLOAD';
        if (isset($options['photo_cover_index']) && is_numeric($options['photo_cover_index'])) {
            $options['photo_cover_index'] = (int) $options['photo_cover_index'];
        }
        return $options;
    }

    public static function error(array $options): ?string
    {
        $options = self::normalize($options);
        if (($options['consent'] ?? false) !== true && ($options['consent'] ?? false) !== 1) return 'Review and approve this content in the TikTok composer before scheduling.';
        if (!in_array($options['mode'] ?? null, ['direct', 'draft'], true)) return 'Choose a TikTok delivery mode.';
        if (!empty($options['post_mode']) && $options['post_mode'] !== ($options['mode'] === 'direct' ? 'DIRECT_POST' : 'MEDIA_UPLOAD')) {
            return 'TikTok post mode does not match the delivery mode.';
        }
        if (isset($options['title']) && self::utf16Length((string) $options['title']) > self::MAX_TITLE_UNITS) return 'TikTok photo titles must be 90 characters or fewer.';
        if ($options['mode'] === 'draft') return null;
        if (!in_array($options['privacy_level'] ?? '', ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'], true)) return 'Choose who can view this TikTok post.';
        if (!empty($options['commercial_content']) && empty($options['brand_organic']) && empty($options['brand_content'])) return 'Choose a commercial content disclosure.';
        if (!empty($options['brand_content']) && ($options['privacy_level'] === 'SELF_ONLY' || empty($options['commercial_content']))) return 'Paid partnership content requires disclosure and cannot be private.';
        return null;
    }

    public static function compositionError(Collection $items, array $options, string $body): ?string
    {
        if ($items->isEmpty()) return null;
        $options = self::normalize($options);
        $photos = $items->filter(fn ($item) => $item instanceof Media && self::isPhoto($item));
        $videos = $items->filter(fn ($item) => $item instanceof Media && $item->isVideo());
        if ($items->contains(fn ($item) => $item instanceof Media && $item->isImageGif())) return 'TikTok does not accept GIFs. Use JPEG or WebP photos, or one video.';
        if ($items->count() !== $photos->count() + $videos->count()) return 'TikTok accepts one video or up to 35 JPEG or WebP photos. PNG files can stay in the library, but TikTok cannot post them.';
        if ($photos->isNotEmpty() && $videos->isNotEmpty()) return 'Choose either TikTok photos or one TikTok video, not both.';
        if ($videos->count() > 1) return 'TikTok accepts one video.';
        if ($videos->isNotEmpty()) return null;
        $max = min(self::MAX_PHOTOS, (int) Util::config('social_provider_options.tiktok.media_limit.photos', self::MAX_PHOTOS));
        if ($photos->count() > $max) return "TikTok photo posts accept up to {$max} images.";
        $cover = $options['photo_cover_index'] ?? 0;
        if (!is_int($cover) || $cover < 0 || $cover >= $photos->count()) return 'Choose a TikTok cover image from the photos in this post.';
        foreach ($photos as $photo) {
            if ((int) $photo->size > self::MAX_PHOTO_BYTES) return 'Each TikTok photo must be 20MB or smaller.';
            $dimensions = self::dimensions($photo);
            if ($dimensions === null) return 'Unable to read this image for TikTok. Upload a JPEG or WebP file.';
            [$width, $height] = $dimensions;
            if (max($width, $height) > 1920 || min($width, $height) > 1080) {
                return 'TikTok photos must be 1080p or smaller: at most 1920 pixels on the long side and 1080 on the short side.';
            }
        }
        [$title, $description] = self::photoText($options, $body);
        if (self::utf16Length($title) > self::MAX_TITLE_UNITS) return 'TikTok photo titles must be 90 characters or fewer.';
        if (self::utf16Length($description) > self::MAX_DESCRIPTION_UNITS) return 'TikTok photo descriptions must be 4000 characters or fewer.';
        return null;
    }

    public static function isPhoto(Media $media): bool
    {
        return in_array(strtolower((string) $media->mime_type), self::PHOTO_MIMES, true);
    }

    /** @return array{0: string, 1: string} */
    public static function photoText(array $options, string $body): array
    {
        $title = trim((string) ($options['title'] ?? ''));
        $description = array_key_exists('description', $options) && $options['description'] !== null && $options['description'] !== ''
            ? (string) $options['description']
            : $body;
        return [$title, $description];
    }

    public static function utf16Length(string $value): int
    {
        if ($value === '') return 0;
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** @return array{0: int, 1: int}|null */
    public static function dimensions(Media $media): ?array
    {
        $temporary = null;
        try {
            if ($media->disk === 'external_media') return null;
            if ($media->isLocalAdapter()) {
                $path = $media->getFullPath();
            } else {
                $download = $media->downloadToTemp();
                $temporary = $download['temporaryDirectory'];
                $path = $download['fullPath'];
            }
            $info = @getimagesize($path);
            if (!$info || empty($info[0]) || empty($info[1])) return null;
            return [(int) $info[0], (int) $info[1]];
        } catch (\Throwable) {
            return null;
        } finally {
            $temporary?->delete();
        }
    }
}
