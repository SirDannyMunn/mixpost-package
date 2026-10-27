<?php

namespace Inovector\Mixpost\SocialProviders\TikTok\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesResources
{
    public function creatorInfo(): SocialProviderResponse
    {
        try {
            $token = $this->getAccessToken();
            $result = Http::withToken($token['access_token'])->withBody('{}', 'application/json')
                ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/creator_info/query/")
                ->throw()->json();
            if (($result['error']['code'] ?? '') !== 'ok' || empty($result['data'])) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok publishing is unavailable for this account.');
            }
            return $this->response(SocialProviderResponseStatus::OK, \Illuminate\Support\Arr::only($result['data'], [
                'creator_avatar_url', 'creator_username', 'creator_nickname', 'privacy_level_options',
                'comment_disabled', 'duet_disabled', 'stitch_disabled', 'max_video_post_duration_sec',
            ]));
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Unable to retrieve TikTok publishing settings.');
        }
    }

    public function getAccount(): SocialProviderResponse
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken['access_token'])
                ->get("{$this->apiUrl}/{$this->apiVersion}/user/info/", [
                    'fields' => 'open_id,union_id,avatar_url,display_name'
                ])
                ->throw()
                ->json();

            if (isset($response['data']['user'])) {
                $user = $response['data']['user'];

                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $user['open_id'],
                    'name' => $user['display_name'],
                    'username' => $user['display_name'],
                    'image' => $user['avatar_url'] ?? ''
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to retrieve account information');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        if ($error = \Inovector\Mixpost\Support\TikTokPostOptions::error($params)) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $error);
        }
        if ($error = \Inovector\Mixpost\Support\TikTokPostMedia::error($media)) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $error);
        }
        $creatorResponse = $this->creatorInfo();
        if ($creatorResponse->hasError()) return $creatorResponse;
        $creator = $creatorResponse->context();
        $direct = $params['mode'] === 'direct';
        if ($direct && !in_array($params['privacy_level'], $creator['privacy_level_options'] ?? [], true)) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok privacy settings changed. Reopen the composer and choose privacy again.');
        }
        if (\Inovector\Mixpost\Support\TikTokPostMedia::isPhotoPost($media)) {
            return $this->publishPhotos($text, $media, $params, $creator);
        }
        $item = $media->first();
        $temporary = null;
        try {
            if ($item->isLocalAdapter()) {
                $path = $item->getFullPath();
            } else {
                $download = $item->downloadToTemp();
                $temporary = $download['temporaryDirectory'];
                $path = $download['fullPath'];
            }
            $duration = (float) \FFMpeg\FFProbe::create(['ffprobe.binaries' => config('mixpost.ffprobe_path')])->format($path)->get('duration');
            $limit = $direct ? (int) ($creator['max_video_post_duration_sec'] ?? 0) : 600;
            if ($duration <= 0 || $duration > $limit) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, "Video must be no longer than {$limit} seconds.");
            }
        } catch (\Throwable $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Unable to verify video duration. Check the uploaded video and media processing service.');
        } finally {
            $temporary?->delete();
        }
        try {
            $payload = ['source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute('social.api.tiktok-media', now()->addHours(2), ['media' => $item->id]),
            ]];
            if ($direct) {
                $payload['post_info'] = [
                    'title' => $text,
                    'privacy_level' => $params['privacy_level'],
                    'disable_comment' => !empty($creator['comment_disabled']) || empty($params['allow_comment']),
                    'disable_duet' => !empty($creator['duet_disabled']) || empty($params['allow_duet']),
                    'disable_stitch' => !empty($creator['stitch_disabled']) || empty($params['allow_stitch']),
                    'brand_organic_toggle' => !empty($params['commercial_content']) && !empty($params['brand_organic']),
                    'brand_content_toggle' => !empty($params['commercial_content']) && !empty($params['brand_content']),
                ];
            }
            $endpoint = $direct ? 'video/init/' : 'inbox/video/init/';
            $result = Http::withToken($this->getAccessToken()['access_token'])->timeout(30)
                ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/{$endpoint}", $payload)->json();
            if (($result['error']['code'] ?? '') !== 'ok' || empty($result['data']['publish_id'])) {
                $code = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($result['error']['code'] ?? 'request_failed'));
                return $this->response(SocialProviderResponseStatus::ERROR, null, "TikTok did not accept this video: {$code}.");
            }
            return $this->response(SocialProviderResponseStatus::OK, [
                'id' => $result['data']['publish_id'],
                'data' => ['delivery_status' => 'PROCESSING_DOWNLOAD', 'delivery_mode' => $params['mode']],
            ]);
        } catch (\Throwable $e) {
            // A timeout may occur after TikTok accepted the request: never automatically repost.
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok submission could not be confirmed. Check TikTok before creating another post.');
        }
    }

    protected function publishPhotos(string $text, Collection $media, array $params, array $creator): SocialProviderResponse
    {
        // TikTok photo API: ordered URLs and cover index refer to the reviewed media order.
        // Photos are served as JPEG from our signed media route on the verified app domain.
        // https://developers.tiktok.com/doc/content-posting-api-reference-photo-post
        $direct = $params['mode'] === 'direct';
        if (intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2) > 4000) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok photo descriptions must be at most 4,000 characters.');
        }
        $payload = [
            'media_type' => 'PHOTO',
            'post_mode' => $direct ? 'DIRECT_POST' : 'MEDIA_UPLOAD',
            'post_info' => ['description' => $text],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_cover_index' => 0,
                'photo_images' => $media->map(fn ($item) => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'social.api.tiktok-media', now()->addHours(2), ['media' => $item->id]
                ))->values()->all(),
            ],
        ];
        if ($direct) {
            $payload['post_info'] += [
                'privacy_level' => $params['privacy_level'],
                'disable_comment' => !empty($creator['comment_disabled']) || empty($params['allow_comment']),
                'auto_add_music' => false,
                'brand_organic_toggle' => !empty($params['commercial_content']) && !empty($params['brand_organic']),
                'brand_content_toggle' => !empty($params['commercial_content']) && !empty($params['brand_content']),
            ];
        }
        try {
            $result = Http::withToken($this->getAccessToken()['access_token'])->timeout(30)
                ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/content/init/", $payload)->json();
            if (($result['error']['code'] ?? '') !== 'ok' || empty($result['data']['publish_id'])) {
                $code = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($result['error']['code'] ?? 'request_failed'));
                return $this->response(SocialProviderResponseStatus::ERROR, null, "TikTok did not accept these photos: {$code}.");
            }
            return $this->response(SocialProviderResponseStatus::OK, [
                'id' => $result['data']['publish_id'],
                'data' => ['delivery_status' => 'PROCESSING_DOWNLOAD', 'delivery_mode' => $params['mode']],
            ]);
        } catch (\Throwable $e) {
            // Never automatically resubmit an uncertain external write.
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok submission could not be confirmed. Check TikTok before creating another post.');
        }
    }

    public function publishStatus(string $publishId): SocialProviderResponse
    {
        try {
            $result = Http::withToken($this->getAccessToken()['access_token'])->timeout(30)
                ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/status/fetch/", ['publish_id' => $publishId])->throw()->json();
            if (($result['error']['code'] ?? '') !== 'ok' || empty($result['data']['status'])) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok status is temporarily unavailable.');
            }
            return $this->response(SocialProviderResponseStatus::OK, \Illuminate\Support\Arr::only($result['data'], ['status', 'fail_reason', 'publicaly_available_post_id']));
        } catch (\Throwable $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok status is temporarily unavailable.');
        }
    }
    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
