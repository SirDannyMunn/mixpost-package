<?php

namespace Inovector\Mixpost\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesResources;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Support\TikTokPostMedia;
use Tests\TestCase;

class TikTokPhotoPostTest extends TestCase
{
    private function photo(string $id, string $mime = 'image/jpeg', string $disk = 'public'): Media
    {
        return (new Media)->forceFill(['id' => $id, 'name' => $id, 'mime_type' => $mime, 'disk' => $disk, 'size' => 1234]);
    }

    private function provider(): object
    {
        return new class {
            use ManagesResources;
            protected string $apiUrl = 'https://open.tiktokapis.com';
            protected string $apiVersion = 'v2';
            public function getAccessToken(): array { return ['access_token' => 'fixture-token']; }
            public function creatorInfo(): SocialProviderResponse
            {
                return new SocialProviderResponse(SocialProviderResponseStatus::OK, ['privacy_level_options' => ['SELF_ONLY'], 'comment_disabled' => true]);
            }
            public function response($status, $context = null, $error = null): SocialProviderResponse
            {
                return new SocialProviderResponse($status, $context ?? [], errorMessage: $error);
            }
        };
    }

    public function test_inbox_upload_preserves_photo_order_caption_and_cover_without_video_probe(): void
    {
        Http::preventStrayRequests();
        Http::fake(['open.tiktokapis.com/*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'photo-accepted']])]);
        $result = $this->provider()->publishPost('Reviewed caption #sales', collect([$this->photo('second', 'image/png'), $this->photo('first')]), ['mode' => 'draft', 'consent' => true]);
        $this->assertTrue($result->isOk());
        $this->assertSame('photo-accepted', $result->id());
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/post/publish/content/init/')
                && $request['media_type'] === 'PHOTO'
                && $request['post_mode'] === 'MEDIA_UPLOAD'
                && $request['post_info'] === ['description' => 'Reviewed caption #sales']
                && $request['source_info']['photo_cover_index'] === 0
                && str_contains($request['source_info']['photo_images'][0], '/second?')
                && str_contains($request['source_info']['photo_images'][1], '/first?');
        });
        Http::assertSentCount(1);
    }

    public function test_direct_photo_post_uses_current_privacy_comment_restrictions_and_disclosures(): void
    {
        Http::fake(['open.tiktokapis.com/*' => Http::response(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'direct-photo']])]);
        $result = $this->provider()->publishPost('Reviewed', collect([$this->photo('cover')]), ['mode' => 'direct', 'consent' => true, 'privacy_level' => 'SELF_ONLY', 'allow_comment' => true, 'commercial_content' => true, 'brand_organic' => true]);
        $this->assertTrue($result->isOk());
        Http::assertSent(fn ($request) => $request['post_mode'] === 'DIRECT_POST'
            && $request['post_info']['privacy_level'] === 'SELF_ONLY'
            && $request['post_info']['disable_comment'] === true
            && $request['post_info']['brand_organic_toggle'] === true
            && $request['post_info']['brand_content_toggle'] === false);
    }

    public function test_invalid_media_and_missing_consent_never_send_to_tiktok(): void
    {
        Http::fake();
        $provider = $this->provider();
        foreach ([collect(), collect([$this->photo('a'), $this->photo('b', 'video/mp4')]), collect([$this->photo('a', 'image/gif')]), collect([$this->photo('a', 'image/jpeg', 'external_media')]), collect(array_fill(0, 36, $this->photo('a')))] as $media) {
            $this->assertNotNull(TikTokPostMedia::error($media));
            $this->assertTrue($provider->publishPost('Reviewed', $media, ['mode' => 'draft', 'consent' => true])->hasError());
        }
        $this->assertTrue($provider->publishPost('Reviewed', collect([$this->photo('a')]), ['mode' => 'draft', 'consent' => false])->hasError());
        Http::assertNothingSent();
    }

    public function test_rejection_and_uncertain_submission_are_reported_without_reposting(): void
    {
        Http::fake(['open.tiktokapis.com/*' => Http::response(['error' => ['code' => 'url_ownership_unverified']])]);
        $result = $this->provider()->publishPost('Reviewed', collect([$this->photo('a')]), ['mode' => 'draft', 'consent' => true]);
        $this->assertTrue($result->hasError());
        Http::assertSentCount(1);
    }

    public function test_timeout_is_not_retried_or_reported_as_success(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('Timed out');
        });
        $result = $this->provider()->publishPost('Reviewed', collect([$this->photo('a')]), ['mode' => 'draft', 'consent' => true]);
        $this->assertTrue($result->hasError());
        $this->assertStringContainsString('could not be confirmed', $result->errorMessage());
        $this->assertSame(1, $attempts);
    }

    public function test_photo_delivery_converts_png_to_jpeg_and_fits_without_cropping(): void
    {
        $controller = new class extends \Inovector\Mixpost\Http\Controllers\Api\TikTokMediaController {
            public function encode(string $contents): string { return $this->encodePhoto($contents); }
        };
        $png = (string) \Intervention\Image\Facades\Image::canvas(1600, 2400, '#ffffff')->encode('png');
        $encoded = $controller->encode($png);
        $info = getimagesizefromstring($encoded);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(1080, $info[0]);
        $this->assertSame(1620, $info[1]);
        $this->assertSame('image/png', getimagesizefromstring($png)['mime']);
    }
}
