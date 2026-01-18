<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesInstagramResources
{
    public function getAccount(): SocialProviderResponse
    {
        $response = Http::get("$this->apiUrl/$this->apiVersion/{$this->values['provider_id']}", [
            'fields' => 'id,username,profile_picture_url',
            'access_token' => $this->accessToken()
        ]);

        return $this->buildResponse($response, function () use ($response) {
            $data = $response->json();

            return [
                'id' => $data['id'],
                'name' => $data['username'],
                'username' => $data['username'],
                'image' => $data['profile_picture_url'] ?? '',
            ];
        });
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        $accessToken = $this->accessToken();
        $igAccountId = $this->values['provider_id'];

        // Instagram requires at least one media item
        if ($media->isEmpty()) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Instagram requires at least one media item');
        }

        $firstMedia = $media->first();

        // Single video post
        if ($media->count() === 1 && $firstMedia->isVideo()) {
            return $this->publishVideoPost($igAccountId, $text, $firstMedia, $accessToken);
        }

        // Single image post
        if ($media->count() === 1 && $firstMedia->isImage()) {
            return $this->publishImagePost($igAccountId, $text, $firstMedia, $accessToken);
        }

        // Carousel post (multiple images/videos)
        if ($media->count() > 1) {
            return $this->publishCarouselPost($igAccountId, $text, $media, $accessToken);
        }

        return $this->response(SocialProviderResponseStatus::ERROR, null, 'Unsupported media configuration for Instagram');
    }

    protected function publishImagePost(string $igAccountId, string $text, Media $media, string $accessToken): SocialProviderResponse
    {
        // Step 1: Create media container
        $imageUrl = $media->isLocalAdapter() ? $media->getUrl() : $media->getUrl();

        $containerResponse = Http::post("$this->apiUrl/$this->apiVersion/$igAccountId/media", [
            'image_url' => $imageUrl,
            'caption' => $text,
            'access_token' => $accessToken
        ]);

        if ($containerResponse->failed()) {
            return $this->buildResponse($containerResponse);
        }

        $containerId = $containerResponse->json('id');

        // Step 2: Publish the container
        $publishResponse = Http::post("$this->apiUrl/$this->apiVersion/$igAccountId/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $accessToken
        ]);

        return $this->buildResponse($publishResponse, function () use ($publishResponse) {
            return [
                'id' => $publishResponse->json('id')
            ];
        });
    }

    protected function publishVideoPost(string $igAccountId, string $text, Media $media, string $accessToken): SocialProviderResponse
    {
        // Step 1: Create video container
        $videoUrl = $media->isLocalAdapter() ? $media->getUrl() : $media->getUrl();

        $containerResponse = Http::post("$this->apiUrl/$this->apiVersion/$igAccountId/media", [
            'media_type' => 'VIDEO',
            'video_url' => $videoUrl,
            'caption' => $text,
            'access_token' => $accessToken
        ]);

        if ($containerResponse->failed()) {
            return $this->buildResponse($containerResponse);
        }

        $containerId = $containerResponse->json('id');

        // Step 2: Wait for video to be processed (poll status)
        $maxAttempts = 20;
        $attempt = 0;
        $isReady = false;

        while ($attempt < $maxAttempts && !$isReady) {
            sleep(3); // Wait 3 seconds between checks

            $statusResponse = Http::get("$this->apiUrl/$this->apiVersion/$containerId", [
                'fields' => 'status_code',
                'access_token' => $accessToken
            ]);

            if ($statusResponse->successful()) {
                $statusCode = $statusResponse->json('status_code');
                if ($statusCode === 'FINISHED') {
                    $isReady = true;
                } elseif ($statusCode === 'ERROR') {
                    return $this->response(SocialProviderResponseStatus::ERROR, null, 'Video processing failed');
                }
            }

            $attempt++;
        }

        if (!$isReady) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Video processing timeout');
        }

        // Step 3: Publish the container
        $publishResponse = Http::post("$this->apiUrl/$this->apiVersion/$igAccountId/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $accessToken
        ]);

        return $this->buildResponse($publishResponse, function () use ($publishResponse) {
            return [
                'id' => $publishResponse->json('id')
            ];
        });
    }

    protected function publishCarouselPost(string $igAccountId, string $text, Collection $media, string $accessToken): SocialProviderResponse
    {
        $childContainers = [];

        // Step 1: Create containers for each media item
        foreach ($media as $item) {
            $mediaUrl = $item->isLocalAdapter() ? $item->getUrl() : $item->getUrl();

            $params = [
                'access_token' => $accessToken,
                'is_carousel_item' => true,
            ];

            if ($item->isImage()) {
                $params['image_url'] = $mediaUrl;
            } elseif ($item->isVideo()) {
                $params['media_type'] = 'VIDEO';
                $params['video_url'] = $mediaUrl;
            } else {
                continue;
            }

            $containerResponse = Http::post("$this->apiUrl/$this->apiVersion/$igAccountId/media", $params);

            if ($containerResponse->successful()) {
                $childContainers[] = $containerResponse->json('id');
            }
        }

        if (empty($childContainers)) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to create carousel containers');
        }

        // Step 2: Create carousel container
        $carouselResponse = Http::post("$this->apiUrl/$this->apiVersion/$igAccountId/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childContainers),
            'caption' => $text,
            'access_token' => $accessToken
        ]);

        if ($carouselResponse->failed()) {
            return $this->buildResponse($carouselResponse);
        }

        $carouselContainerId = $carouselResponse->json('id');

        // Step 3: Publish the carousel
        $publishResponse = Http::post("$this->apiUrl/$this->apiVersion/$igAccountId/media_publish", [
            'creation_id' => $carouselContainerId,
            'access_token' => $accessToken
        ]);

        return $this->buildResponse($publishResponse, function () use ($publishResponse) {
            return [
                'id' => $publishResponse->json('id')
            ];
        });
    }

    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
