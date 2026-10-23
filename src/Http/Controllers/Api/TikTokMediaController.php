<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\Request;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\TikTokJpeg;

class TikTokMediaController
{
    public function __invoke(Request $request, string $media)
    {
        abort_unless($this->hasValidMediaSignature($request), 403);
        $item = Media::findOrFail($media);
        // Never proxy arbitrary external URLs through a signed endpoint.
        // TikTok photo posts pull JPEG or WebP. PNG slides are converted here.
        $mime = strtolower((string) $item->mime_type);
        $png = $mime === 'image/png';
        $photo = $png || in_array($mime, ['image/jpeg', 'image/jpg', 'image/webp'], true);
        abort_if($item->disk === 'external_media' || !($item->isVideo() || $photo), 404);
        $stream = $item->filesystem()->readStream($item->path);
        abort_unless(is_resource($stream), 404);
        if ($png) {
            try {
                $binary = stream_get_contents($stream);
                $jpeg = is_string($binary) ? TikTokJpeg::fromPng($binary) : '';
            } catch (\Throwable) {
                $jpeg = '';
            } finally {
                fclose($stream);
            }
            abort_if($jpeg === '', 404);
            return response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg), 'Cache-Control' => 'private, no-store']);
        }
        return response()->stream(function () use ($stream) {
            try { fpassthru($stream); } finally { fclose($stream); }
        }, 200, ['Content-Type' => $item->mime_type, 'Content-Length' => (string) $item->size, 'Cache-Control' => 'private, no-store']);
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
