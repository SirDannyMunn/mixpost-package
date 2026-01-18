<?php

namespace Inovector\Mixpost\SocialProviders\TikTok\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesResources
{
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
        try {
            $accessToken = $this->getAccessToken();

            // TikTok requires video or slideshow media
            if ($media->isEmpty()) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'TikTok requires at least one video or image for posting');
            }

            $firstMedia = $media->first();

            // Handle video upload
            if ($firstMedia->isVideo()) {
                return $this->publishVideo($text, $firstMedia, $accessToken);
            }

            // Handle slideshow (photo mode) - multiple images
            if ($firstMedia->isImage()) {
                return $this->publishPhotoSlideshow($text, $media, $accessToken);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Unsupported media type for TikTok');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    protected function publishVideo(string $text, $media, array $accessToken): SocialProviderResponse
    {
        try {
            // Step 1: Initialize video upload
            $initResponse = Http::withToken($accessToken['access_token'])
                ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/video/init/", [
                    'post_info' => [
                        'title' => $text ?: 'Video Post',
                        'privacy_level' => 'SELF_ONLY',
                        'disable_duet' => false,
                        'disable_comment' => false,
                        'disable_stitch' => false,
                        'video_cover_timestamp_ms' => 1000
                    ],
                    'source_info' => [
                        'source' => 'FILE_UPLOAD',
                        'video_size' => $media->size,
                        'chunk_size' => 10000000,
                        'total_chunk_count' => 1
                    ]
                ])
                ->throw()
                ->json();

            if (!isset($initResponse['data']['publish_id']) || !isset($initResponse['data']['upload_url'])) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to initialize video upload');
            }

            $publishId = $initResponse['data']['publish_id'];
            $uploadUrl = $initResponse['data']['upload_url'];

            // Step 2: Upload video file
            $videoPath = $media->isLocalAdapter() ? $media->getFullPath() : $media->getUrl();
            
            $uploadResponse = Http::withHeaders([
                'Content-Type' => $media->mime_type,
                'Content-Range' => 'bytes 0-' . ($media->size - 1) . '/' . $media->size
            ])->put($uploadUrl, file_get_contents($videoPath));

            if (!$uploadResponse->successful()) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to upload video file');
            }

            // Step 3: Publish the video
            $publishResponse = Http::withToken($accessToken['access_token'])
                ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/status/fetch/", [
                    'publish_id' => $publishId
                ])
                ->throw()
                ->json();

            // Return immediately with publish_id, actual publishing happens async on TikTok side
            return $this->response(SocialProviderResponseStatus::OK, [
                'id' => $publishId
            ]);
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    protected function publishPhotoSlideshow(string $text, Collection $media, array $accessToken): SocialProviderResponse
    {
        try {
            $images = [];

            // Upload each image
            foreach ($media as $item) {
                if (!$item->isImage()) {
                    continue;
                }

                // Step 1: Initialize image upload
                $initResponse = Http::withToken($accessToken['access_token'])
                    ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/inbox/video/init/", [
                        'source_info' => [
                            'source' => 'FILE_UPLOAD'
                        ]
                    ])
                    ->throw()
                    ->json();

                if (!isset($initResponse['data']['upload_url'])) {
                    continue;
                }

                $uploadUrl = $initResponse['data']['upload_url'];

                // Step 2: Upload image
                $imagePath = $item->isLocalAdapter() ? $item->getFullPath() : $item->getUrl();
                
                $uploadResponse = Http::withHeaders([
                    'Content-Type' => $item->mime_type
                ])->put($uploadUrl, file_get_contents($imagePath));

                if ($uploadResponse->successful() && isset($initResponse['data']['photo_id'])) {
                    $images[] = ['photo_id' => $initResponse['data']['photo_id']];
                }
            }

            if (empty($images)) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to upload images');
            }

            // Step 3: Create photo post
            $publishResponse = Http::withToken($accessToken['access_token'])
                ->post("{$this->apiUrl}/{$this->apiVersion}/post/publish/content/init/", [
                    'post_info' => [
                        'title' => $text ?: 'Photo Post',
                        'privacy_level' => 'SELF_ONLY',
                        'disable_duet' => false,
                        'disable_comment' => false,
                        'disable_stitch' => false
                    ],
                    'source_info' => [
                        'source' => 'PHOTO_MODE',
                        'photo_images' => $images
                    ]
                ])
                ->throw()
                ->json();

            if (isset($publishResponse['data']['publish_id'])) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $publishResponse['data']['publish_id']
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to publish slideshow');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
