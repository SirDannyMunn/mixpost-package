<?php

namespace Inovector\Mixpost\Http\Controllers\Api;

use Illuminate\Http\Request;
use Inovector\Mixpost\Models\Media;

class TikTokMediaController
{
    public function __invoke(Request $request, string $media)
    {
        abort_unless($request->hasValidSignature(), 403);
        $item = Media::findOrFail($media);
        // Never proxy arbitrary external URLs through a signed endpoint.
        abort_if($item->disk === 'external_media' || !$item->isVideo(), 404);
        $stream = $item->filesystem()->readStream($item->path);
        abort_unless(is_resource($stream), 404);
        return response()->stream(function () use ($stream) {
            try { fpassthru($stream); } finally { fclose($stream); }
        }, 200, ['Content-Type' => $item->mime_type, 'Content-Length' => (string) $item->size, 'Cache-Control' => 'private, no-store']);
    }
}
