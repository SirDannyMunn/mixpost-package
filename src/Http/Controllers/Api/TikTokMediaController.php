<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\Request;
use Inovector\Mixpost\Models\Media;

class TikTokMediaController
{
    public function __invoke(Request $request, string $media)
    {
        abort_unless($this->hasValidMediaSignature($request), 403);
        $item = Media::findOrFail($media);
        // Never proxy arbitrary external URLs through a signed endpoint.
        abort_if($item->disk === 'external_media' || (!$item->isVideo() && !in_array($item->mime_type, ['image/jpeg', 'image/webp', 'image/png'], true)), 404);
        if ($item->isImage()) {
            $encoded = $this->encodePhoto($item->contents());
            return response($encoded, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($encoded), 'Cache-Control' => 'private, no-store']);
        }
        $stream = $item->filesystem()->readStream($item->path);
        abort_unless(is_resource($stream), 404);
        return response()->stream(function () use ($stream) {
            try { fpassthru($stream); } finally { fclose($stream); }
        }, 200, ['Content-Type' => $item->mime_type, 'Content-Length' => (string) $item->size, 'Cache-Control' => 'private, no-store']);
    }

    protected function encodePhoto(string $contents): string
    {
        // Normalize PNGs and oversized originals without changing the stored original.
        $image = \Intervention\Image\Facades\Image::make($contents)->orientate();
        $portrait = $image->height() >= $image->width();
        $image->resize($portrait ? 1080 : 1920, $portrait ? 1920 : 1080, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        return (string) $image->encode('jpg', 90);
    }

    protected function hasValidMediaSignature(Request $request): bool
    {
        // Proxy host/scheme normalization must not change the signed public URL.
        // Rebuild only from trusted configuration; retain the exact path and query.
        $publicUrl = rtrim((string) config('app.url'), '/');
        if (!filter_var($publicUrl, FILTER_VALIDATE_URL)) return false;
        $canonical = Request::create($publicUrl.$request->getPathInfo().'?'.$request->server('QUERY_STRING', ''));
        return \Illuminate\Support\Facades\URL::hasValidSignature($canonical);
    }
}
