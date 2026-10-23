<?php

namespace Inovector\Mixpost\Tests\Feature;

use App\Models\{Organization, OrganizationMember, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Storage, URL};
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Actions\AccountPublishPost;
use Inovector\Mixpost\Enums\PostStatus;
use Inovector\Mixpost\Jobs\CheckTikTokPostStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Services\Integrations\SharedSocialConnection;
use Inovector\Mixpost\Services\Scheduler\{SchedulerService, SocialMediaLibrary};
use Inovector\Mixpost\Support\PublicMediaUrl;
use Laravel\Sanctum\Sanctum;
use LaundryOS\Mcp\Services\SchedulerMcpService;
use Tests\TestCase;

class TikTokPhotoPostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private SchedulerService $posts;
    private string $tiktokStatus = 'PUBLISH_COMPLETE';

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required to build TikTok photo fixtures.');
        }
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create();
        OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        Sanctum::actingAs($this->user);
        config()->set('app.url', 'https://api.usetwl.com');
        config()->set('integrations.nango.base_url', 'https://nango.test');
        config()->set('integrations.nango.secret_key', 'test-server-secret');
        URL::forceRootUrl('https://api.usetwl.com');
        URL::forceScheme('https');
        $this->app->forgetInstance(\LaundryOS\Integrations\Modules\Nango\NangoClient::class);
        Http::preventStrayRequests();
        Http::fake([
            'https://nango.test/connections/*' => Http::response(['provider' => 'tiktok-personal', 'tags' => ['organization_id' => $this->org->id], 'credentials' => ['access_token' => 'fresh-token']]),
            'https://open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => 'creator-1', 'display_name' => 'Test creator', 'avatar_url' => '']]]),
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['error' => ['code' => 'ok'], 'data' => [
                'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
                'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => true, 'max_video_post_duration_sec' => 300,
            ]]),
            'https://open.tiktokapis.com/v2/post/publish/content/init/' => Http::response(['error' => ['code' => 'ok'], 'data' => ['publish_id' => 'photo-pub-1']]),
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => function () {
                return Http::response(['error' => ['code' => 'ok'], 'data' => [
                    'status' => $this->tiktokStatus,
                    'publicaly_available_post_id' => $this->tiktokStatus === 'PUBLISH_COMPLETE' ? ['7123456789'] : [],
                ]]);
            },
        ]);
        $this->posts = app(SchedulerService::class);
    }

    public function test_accounts_advertise_thirty_five_tiktok_photos(): void
    {
        $account = $this->account();
        $row = collect($this->posts->accounts($this->org->id))->firstWhere('id', $account->id);
        $this->assertSame(35, $row['capabilities']['media_limit']['max']['photos']['default']);
        $this->assertSame(0, $row['capabilities']['media_limit']['min']['photos']['default']);
        $this->assertSame(1, $row['capabilities']['media_limit']['max']['videos']['default']);
        $this->assertSame(0, $row['capabilities']['media_limit']['min']['videos']['default']);
    }

    public function test_draft_validation_and_schedule_never_call_tiktok(): void
    {
        $account = $this->account();
        [$first, $second] = [$this->image('one.jpg'), $this->image('two.jpg', 1080, 1920)];
        $draft = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, [$first->id, $second->id], [
            'mode' => 'draft', 'consent' => true, 'title' => 'Inbox only', 'photo_cover_index' => 1,
        ], 'Carousel description'));
        $this->assertSame('DRAFT', $draft['status']);
        $this->assertSame('MEDIA_UPLOAD', $draft['versions'][0]['options']['tiktok']['post_mode']);
        $result = $this->posts->validate($this->org->id, $draft['id']);
        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $scheduled = $this->posts->schedule($this->org->id, $draft['id'], now()->addHour()->toIso8601String());
        $this->assertSame('SCHEDULED', $scheduled['status']);
        $this->assertNull($scheduled['published_at']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/post/publish/'));
    }

    public function test_inbox_carousel_publishes_only_when_the_worker_runs_and_then_polls(): void
    {
        $account = $this->account();
        $ids = [$this->image('one.jpg')->id, $this->image('two.jpg')->id];
        $draft = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, $ids, [
            'mode' => 'draft', 'consent' => true, 'title' => 'Cover title', 'photo_cover_index' => 1, 'is_aigc' => true,
        ], 'Carousel text'));
        $post = $this->posts->post($this->org->id, $draft['id']);
        $post->setScheduleProcessing();
        $response = app(AccountPublishPost::class)($account->fresh(), $post);
        $this->assertFalse($response->hasError(), $response->errorMessage() ?? json_encode($response->context()));
        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/post/publish/content/init/')) return false;
            $body = $request->data();
            $this->assertSame('PHOTO', $body['media_type']);
            $this->assertSame('MEDIA_UPLOAD', $body['post_mode']);
            $this->assertSame('PULL_FROM_URL', $body['source_info']['source']);
            $this->assertSame(1, $body['source_info']['photo_cover_index']);
            $this->assertCount(2, $body['source_info']['photo_images']);
            $this->assertStringStartsWith('https://api.usetwl.com/api/v1/social/tiktok-media/', $body['source_info']['photo_images'][0]);
            $this->assertStringStartsWith('https://', $body['source_info']['photo_images'][1]);
            $this->assertSame('Cover title', $body['post_info']['title']);
            $this->assertSame('Carousel text', $body['post_info']['description']);
            $this->assertArrayNotHasKey('privacy_level', $body['post_info']);
            $this->assertTrue($body['is_aigc']);
            $this->assertSame('Bearer fresh-token', $request->header('Authorization')[0]);
            return true;
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'creator_info'));
        $post->unsetRelation('accounts');
        (new CheckTikTokPostStatus($post))->handle();
        $fresh = $this->posts->serialize($post->fresh());
        $this->assertSame('PUBLISHED', $fresh['status']);
        $this->assertSame('PHOTO', $fresh['accounts'][0]['media_type']);
        $this->assertSame('MEDIA_UPLOAD', $fresh['accounts'][0]['post_mode']);
        $this->assertSame('PUBLISH_COMPLETE', $fresh['accounts'][0]['delivery_status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/status/fetch/') && $request->data()['publish_id'] === 'photo-pub-1');
    }

    public function test_direct_photo_post_uses_direct_post_and_creator_privacy(): void
    {
        $account = $this->account();
        $draft = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, [$this->image('one.jpg')->id], [
            'mode' => 'direct', 'post_mode' => 'DIRECT_POST', 'consent' => true, 'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'title' => 'Live', 'auto_add_music' => true, 'allow_comment' => false,
        ], 'Public description'));
        $this->posts->validate($this->org->id, $draft['id']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/content/init/'));
        $post = $this->posts->post($this->org->id, $draft['id']);
        $response = app(AccountPublishPost::class)($account->fresh(), $post);
        $this->assertFalse($response->hasError(), $response->errorMessage() ?? json_encode($response->context()));
        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/content/init/')) return false;
            $body = $request->data();
            $this->assertSame('DIRECT_POST', $body['post_mode']);
            $this->assertSame('PUBLIC_TO_EVERYONE', $body['post_info']['privacy_level']);
            $this->assertTrue($body['post_info']['auto_add_music']);
            $this->assertTrue($body['post_info']['disable_comment']);
            $this->assertFalse($body['post_info']['brand_content_toggle']);
            return true;
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), 'creator_info'));
    }

    public function test_inbox_status_is_not_reported_as_publication(): void
    {
        $this->tiktokStatus = 'SEND_TO_USER_INBOX';
        $account = $this->account();
        $draft = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, [$this->image('one.jpg')->id], [
            'post_mode' => 'MEDIA_UPLOAD', 'consent' => true, 'title' => 'Wait',
        ], 'Waiting in inbox'));
        $this->assertSame('draft', $draft['versions'][0]['options']['tiktok']['mode']);
        $post = $this->posts->post($this->org->id, $draft['id']);
        $post->setScheduleProcessing();
        $this->assertFalse(app(AccountPublishPost::class)($account->fresh(), $post)->hasError());
        $post->unsetRelation('accounts');
        (new CheckTikTokPostStatus($post))->handle();
        $fresh = $post->fresh();
        $this->assertSame(PostStatus::DELIVERED, $fresh->status);
        $this->assertNull($fresh->published_at);
    }

    public function test_invalid_photo_posts_are_rejected_without_calling_tiktok(): void
    {
        $account = $this->account();
        $huge = $this->image('huge.jpg', 2000, 2000);
        $hugeDraft = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, [$huge->id], ['mode' => 'draft', 'consent' => true], 'Too big'));
        $this->assertStringContainsString('1080p', implode(' ', $this->posts->validate($this->org->id, $hugeDraft['id'])['errors']));

        $one = $this->image('only.jpg');
        $cover = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, [$one->id], ['mode' => 'draft', 'consent' => true, 'photo_cover_index' => 4], 'Cover'));
        $this->assertStringContainsString('cover', implode(' ', $this->posts->validate($this->org->id, $cover['id'])['errors']));

        $long = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, [$one->id], ['mode' => 'draft', 'consent' => true, 'title' => str_repeat('a', 91)], 'Ok'));
        $this->assertStringContainsString('90', implode(' ', $this->posts->validate($this->org->id, $long['id'])['errors']));

        try {
            $ids = [];
            for ($i = 0; $i < 36; $i++) $ids[] = $this->image("n-$i.jpg", 2, 2)->id;
            $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, $ids, ['mode' => 'draft', 'consent' => true], 'Too many'));
            $this->fail('A 36-image carousel was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/post/publish/'));
    }

    public function test_eight_png_carousel_validates_as_a_media_upload_draft_without_calling_tiktok(): void
    {
        $account = $this->account();
        $tiktokCalls = fn () => collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'tiktokapis.com'))->count();
        $before = $tiktokCalls();
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = $this->image("slide-$i.png", 1080, 1920, 'image/png')->id;
        }
        $draft = $this->posts->draft($this->org->id, $this->user->id, $this->payload($account->id, $ids, [
            'mode' => 'draft', 'consent' => true, 'title' => 'PNG slides', 'photo_cover_index' => 0,
        ], 'Eight slides'));
        $this->assertSame('DRAFT', $draft['status']);
        $this->assertSame('MEDIA_UPLOAD', $draft['versions'][0]['options']['tiktok']['post_mode']);
        $result = $this->posts->validate($this->org->id, $draft['id']);
        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame($before, $tiktokCalls());

        $served = parse_url(URL::temporarySignedRoute('social.api.tiktok-media', now()->addMinutes(5), ['media' => $ids[0]]));
        $response = $this->call('GET', $served['path'].'?'.$served['query']);
        $response->assertOk()->assertHeader('content-type', 'image/jpeg');
        $jpeg = $response->getContent();
        $this->assertStringStartsWith("\xFF\xD8", $jpeg);
        $this->assertStringContainsString('Exif', $jpeg);
        $info = getimagesizefromstring($jpeg);
        $this->assertSame(1080, $info[0]);
        $this->assertSame(1920, $info[1]);
        $this->assertSame($before, $tiktokCalls());
    }

    public function test_upload_stores_base64_and_public_url_without_publishing(): void
    {
        $jpeg = $this->jpegBytes(12, 12);
        $library = new SocialMediaLibrary(new PublicMediaUrl(fn () => ['93.184.216.34']));
        $this->app->instance(SocialMediaLibrary::class, $library);
        Http::fake([
            'https://cdn.example.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $stored = app(SchedulerMcpService::class)->execute('upload_media', [
            'organization_id' => $this->org->id,
            'content_base64' => base64_encode($jpeg),
            'filename' => 'library frame.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $this->assertSame('image/jpeg', $stored['media']['mime_type']);
        $this->assertNotEmpty($stored['media']['id']);
        $listed = app(SchedulerMcpService::class)->execute('media', ['organization_id' => $this->org->id]);
        $this->assertTrue(collect($listed['media'])->contains(fn ($row) => $row['id'] === $stored['media']['id']));

        $fromUrl = $library->store($this->org->id, $this->user->id, ['url' => 'https://cdn.example.com/photos/frame.jpg']);
        $this->assertSame('image/jpeg', $fromUrl['mime_type']);
        $this->assertNotSame($stored['media']['id'], $fromUrl['id']);

        $pngStored = $library->store($this->org->id, $this->user->id, [
            'content_base64' => base64_encode($this->transparentPng()),
            'filename' => 'slide.png',
            'mime_type' => 'image/png',
        ]);
        $this->assertSame('image/jpeg', $pngStored['mime_type']);
        $this->assertStringEndsWith('.jpg', $pngStored['name']);
        $converted = Storage::disk('public')->get(Media::findOrFail($pngStored['id'])->path);
        $this->assertStringStartsWith("\xFF\xD8", $converted);
        $decoded = imagecreatefromstring($converted);
        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $this->assertSame(['red' => 255, 'green' => 255, 'blue' => 255], array_intersect_key($corner, array_flip(['red', 'green', 'blue'])));
        imagedestroy($decoded);

        try {
            $library->store($this->org->id, $this->user->id, ['url' => 'https://10.1.2.3/secret.jpg']);
            $this->fail('A private URL was fetched.');
        } catch (ValidationException) {
        }
        try {
            $library->store($this->org->id, $this->user->id, [
                'content_base64' => base64_encode($jpeg), 'filename' => 'x.jpg', 'mime_type' => 'image/png',
            ]);
            $this->fail('A mismatched mime type was stored.');
        } catch (ValidationException) {
        }
        try {
            $library->store($this->org->id, $this->user->id, [
                'content_base64' => base64_encode("GIF89a\x01\x00\x01\x00"), 'filename' => 'a.gif', 'mime_type' => 'image/gif',
            ]);
            $this->fail('A GIF was stored.');
        } catch (ValidationException) {
        }
        config()->set('mixpost.max_file_size.image', 1);
        try {
            $library->store($this->org->id, $this->user->id, [
                'content_base64' => base64_encode($this->jpegBytes(800, 800)), 'filename' => 'big.jpg', 'mime_type' => 'image/jpeg',
            ]);
            $this->fail('An oversized image was stored.');
        } catch (ValidationException) {
        }
        try {
            $library->store($this->org->id, $this->user->id, []);
            $this->fail('An upload with neither url nor file content was stored.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Provide a public url or base64 file content.'], $exception->errors()['file']);
        }
        try {
            $library->store($this->org->id, $this->user->id, ['url' => '', 'content_base64' => '']);
            $this->fail('An upload with blank url and file content was stored.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Provide a public url or base64 file content.'], $exception->errors()['file']);
        }
        try {
            $library->store($this->org->id, $this->user->id, [
                'url' => 'https://cdn.example.com/photos/frame.jpg',
                'content_base64' => base64_encode($jpeg),
                'filename' => 'frame.jpg',
                'mime_type' => 'image/jpeg',
            ]);
            $this->fail('An upload with both url and file content was stored.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Provide either a public url or base64 file content, not both.'], $exception->errors()['file']);
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tiktokapis.com'));
    }

    public function test_signed_media_route_serves_jpeg_and_converts_png(): void
    {
        $jpeg = $this->image('served.jpg');
        $png = $this->imageBytes('served.png', $this->transparentPng());
        $ok = parse_url(URL::temporarySignedRoute('social.api.tiktok-media', now()->addMinutes(5), ['media' => $jpeg->id]));
        $this->call('GET', $ok['path'].'?'.$ok['query'])->assertOk()->assertHeader('content-type', 'image/jpeg');
        $converted = parse_url(URL::temporarySignedRoute('social.api.tiktok-media', now()->addMinutes(5), ['media' => $png->id]));
        $response = $this->call('GET', $converted['path'].'?'.$converted['query']);
        $response->assertOk()->assertHeader('content-type', 'image/jpeg');
        $body = $response->getContent();
        $this->assertStringStartsWith("\xFF\xD8", $body);
        $decoded = imagecreatefromstring($body);
        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $this->assertSame(255, $corner['red']);
        $this->assertSame(255, $corner['green']);
        $this->assertSame(255, $corner['blue']);
        imagedestroy($decoded);
    }

    private function account()
    {
        return app(SharedSocialConnection::class)->link($this->org->id, $this->user->id, 'tiktok-personal', 'connection-1', 'creator-1');
    }

    private function payload(string $accountId, array $media, array $tiktok, string $body): array
    {
        return [
            'idempotency_key' => 'tiktok-'.md5(json_encode([$media, $tiktok, $body])),
            'accounts' => [$accountId],
            'versions' => [[
                'is_original' => true,
                'account_id' => null,
                'options' => ['tiktok' => $tiktok],
                'content' => [['body' => $body, 'media' => $media]],
            ]],
        ];
    }

    private function image(string $name, int $width = 8, int $height = 8, string $mime = 'image/jpeg'): Media
    {
        $bytes = $mime === 'image/png' ? $this->pngBytes($width, $height) : $this->jpegBytes($width, $height);
        $path = 'tiktok-tests/'.$name;
        Storage::disk('public')->put($path, $bytes);
        return Media::create([
            'organization_id' => $this->org->id,
            'uploaded_by' => $this->user->id,
            'name' => $name,
            'mime_type' => $mime,
            'disk' => 'public',
            'path' => $path,
            'size' => strlen($bytes),
            'size_total' => strlen($bytes),
            'conversions' => [],
        ]);
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 80, 140));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 20, 20));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function transparentPng(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, 31, 31, $clear);
        $red = imagecolorallocatealpha($image, 220, 30, 30, 0);
        imagesetpixel($image, 31, 31, $red);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function imageBytes(string $name, string $bytes): Media
    {
        $path = 'tiktok-tests/'.$name;
        Storage::disk('public')->put($path, $bytes);
        return Media::create([
            'organization_id' => $this->org->id,
            'uploaded_by' => $this->user->id,
            'name' => $name,
            'mime_type' => 'image/png',
            'disk' => 'public',
            'path' => $path,
            'size' => strlen($bytes),
            'size_total' => strlen($bytes),
            'conversions' => [],
        ]);
    }
}
