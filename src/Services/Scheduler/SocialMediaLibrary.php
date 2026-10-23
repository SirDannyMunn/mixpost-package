<?php

namespace Inovector\Mixpost\Services\Scheduler;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\MediaConversions\MediaImageResizeConversion;
use Inovector\Mixpost\MediaConversions\MediaVideoThumbConversion;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\MediaUploader;
use Inovector\Mixpost\Support\PublicMediaUrl;

/** Stores files in the social media library. Uploading does not publish. */
class SocialMediaLibrary
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    public const VIDEO_MIMES = ['video/mp4', 'video/x-m4v'];

    public function __construct(private readonly ?PublicMediaUrl $urls = null) {}

    public function store(string $organizationId, ?string $actorId, array $input): array
    {
        $file = $this->file($input);
        $request = request();
        $previous = $request->attributes->get('organization');
        $request->attributes->set('organization', (object) ['id' => $organizationId]);
        try {
            $media = MediaUploader::fromFile($file)
                ->path(now()->format('m-Y'))
                ->conversions([
                    MediaImageResizeConversion::name('thumb')->width(430),
                    MediaVideoThumbConversion::name('thumb')->atSecond(5),
                ])
                ->uploadAndInsert();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored in the social media library.']);
        } finally {
            if ($previous === null) $request->attributes->remove('organization');
            else $request->attributes->set('organization', $previous);
            $path = $file->getPathname();
            if (is_file($path)) @unlink($path);
        }
        $media->forceFill([
            'organization_id' => $organizationId,
            'uploaded_by' => $actorId ?: $media->uploaded_by,
            'name' => $file->getClientOriginalName(),
        ])->save();

        return $media->fresh()->only('id', 'name', 'mime_type', 'size');
    }

    private function file(array $input): UploadedFile
    {
        $hasUrl = isset($input['url']) && $input['url'] !== '';
        $hasBytes = isset($input['content_base64']) && $input['content_base64'] !== '';
        if ($hasUrl === $hasBytes) {
            throw ValidationException::withMessages(['file' => 'Provide either a public url or base64 file content, not both.']);
        }
        if ($hasBytes) {
            if (trim((string) ($input['filename'] ?? '')) === '') {
                throw ValidationException::withMessages(['filename' => 'A filename is required with file content.']);
            }
            $claimed = $this->normalizeMime((string) ($input['mime_type'] ?? ''));
            $binary = $this->decode((string) $input['content_base64'], $claimed);
            return $this->uploaded($binary, (string) $input['filename'], $claimed);
        }
        $claimed = isset($input['mime_type']) && $input['mime_type'] !== '' ? $this->normalizeMime((string) $input['mime_type']) : '';
        $binary = $this->download((string) $input['url'], $claimed !== '' ? $claimed : null);
        return $this->uploaded($binary, $this->nameFromUrl((string) $input['url'], (string) ($input['filename'] ?? '')), $claimed);
    }

    private function download(string $url, ?string $claimed): string
    {
        $guard = $this->urls ?? new PublicMediaUrl();
        $guard->assertFetchable($url);
        $limit = $this->maxBytes($claimed ?? 'video/mp4');
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 60,
                'connect_timeout' => 10,
                'stream' => true,
            ])->withHeaders(['Accept' => 'image/jpeg,image/png,image/webp,video/mp4,video/x-m4v', 'User-Agent' => 'TWL-SocialMedia/1.0'])
                ->get($url);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['url' => 'The media URL could not be downloaded.']);
        }
        if ($response->status() !== 200) {
            throw ValidationException::withMessages(['url' => 'The media URL must return the file directly over HTTPS with no redirect.']);
        }
        $length = $response->header('Content-Length');
        if (is_numeric($length) && (int) $length > $limit) {
            throw ValidationException::withMessages(['file' => 'The file is larger than the social library allows.']);
        }
        $body = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (!$body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > $limit) {
                throw ValidationException::withMessages(['file' => 'The file is larger than the social library allows.']);
            }
        }
        return $bytes;
    }

    private function decode(string $encoded, string $claimed): string
    {
        if ($claimed === '') throw ValidationException::withMessages(['mime_type' => 'A mime type is required with file content.']);
        $payload = preg_replace('#^data:[^,]+,#', '', $encoded) ?? '';
        $limit = $this->maxBytes($claimed);
        if (strlen($payload) > (int) ceil($limit / 3) * 4 + 4) {
            throw ValidationException::withMessages(['file' => 'The file is larger than the social library allows.']);
        }
        $binary = base64_decode($payload, true);
        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages(['content_base64' => 'File content must be valid base64.']);
        }
        if (strlen($binary) > $limit) {
            throw ValidationException::withMessages(['file' => 'The file is larger than the social library allows.']);
        }
        return $binary;
    }

    private function uploaded(string $binary, string $filename, string $claimed): UploadedFile
    {
        $detected = $this->sniff($binary);
        if ($claimed !== '' && !$this->equivalent($claimed, $detected)) {
            throw ValidationException::withMessages(['mime_type' => 'The file contents do not match the declared type.']);
        }
        if (strlen($binary) > $this->maxBytes($detected)) {
            throw ValidationException::withMessages(['file' => 'The file is larger than the social library allows.']);
        }
        $filename = $this->filename($filename, $detected);
        $temporary = tempnam(sys_get_temp_dir(), 'social-media-');
        if ($temporary === false || file_put_contents($temporary, $binary) === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored in the social media library.']);
        }
        return new UploadedFile($temporary, $filename, $detected, \UPLOAD_ERR_OK, true);
    }

    private function sniff(string $binary): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: '';
        return $this->normalizeMime($detected);
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim(strtok($mime, ';') ?: ''));
        if ($mime === 'image/jpg' || $mime === 'image/pjpeg') return 'image/jpeg';
        if ($mime === 'video/m4v') return 'video/x-m4v';
        if (!in_array($mime, [...self::IMAGE_MIMES, ...self::VIDEO_MIMES], true)) {
            if ($mime === '') return '';
            throw ValidationException::withMessages(['mime_type' => 'Use a PNG, JPEG, or WebP image, or an MP4 video.']);
        }
        return $mime;
    }

    private function equivalent(string $claimed, string $detected): bool
    {
        if ($claimed === $detected) return true;
        $video = ['video/mp4', 'video/x-m4v'];
        return in_array($claimed, $video, true) && in_array($detected, $video, true);
    }

    private function maxBytes(string $mime): int
    {
        $kilobytes = str_starts_with($mime, 'video/')
            ? (int) config('mixpost.max_file_size.video', 1024 * 200)
            : (int) config('mixpost.max_file_size.image', 1024 * 5);
        return $kilobytes * 1024;
    }

    private function filename(string $name, string $mime): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: '';
        $base = trim((string) $base, '.-');
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/x-m4v' => 'm4v',
            default => 'bin',
        };
        if ($base === '') $base = 'upload.'.$extension;
        if (!str_ends_with(strtolower($base), '.'.$extension)) $base .= '.'.$extension;
        return substr($base, 0, 180);
    }

    private function nameFromUrl(string $url, string $preferred): string
    {
        if ($preferred !== '') return $preferred;
        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
