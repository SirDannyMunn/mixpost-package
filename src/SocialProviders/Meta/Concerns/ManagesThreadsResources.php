<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesThreadsResources
{
    public function getAccount(): SocialProviderResponse
    {
        $response = Http::get('https://graph.threads.net/v1.0/me', [
            'fields' => 'id,username,threads_profile_picture_url',
            'access_token' => $this->accessToken()
        ]);

        return $this->buildResponse($response, function () use ($response) {
            $data = $response->json();

            return [
                'id' => $data['id'],
                'name' => $data['username'] ?? '',
                'username' => $data['username'] ?? '',
                'image' => Arr::get($data, 'threads_profile_picture_url', ''),
            ];
        });
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        $threadsUserId = $this->values['provider_id'];
        $hasMedia = $media->isNotEmpty();

        // Step 1: Create media container(s)
        if ($hasMedia) {
            $mediaItem = $media->first();
            
            if ($mediaItem->isImage()) {
                return $this->publishImagePost($text, $mediaItem, $threadsUserId);
            } else if ($mediaItem->isVideo()) {
                return $this->publishVideoPost($text, $mediaItem, $threadsUserId);
            }
        }

        // Text-only post
        return $this->publishTextPost($text, $threadsUserId);
    }

    protected function publishTextPost(string $text, string $threadsUserId): SocialProviderResponse
    {
        // Step 1: Create text container
        $containerResponse = Http::post("https://graph.threads.net/v1.0/{$threadsUserId}/threads", [
            'media_type' => 'TEXT',
            'text' => $text,
            'access_token' => $this->accessToken()
        ]);

        if ($containerResponse->failed()) {
            return $this->buildResponse($containerResponse);
        }

        $containerId = $containerResponse->json()['id'];

        // Step 2: Publish the container
        return $this->publishContainer($threadsUserId, $containerId);
    }

    protected function publishImagePost(string $text, Media $mediaItem, string $threadsUserId): SocialProviderResponse
    {
        $imageUrl = $mediaItem->isLocalAdapter() ? $mediaItem->getUrl() : $mediaItem->getFullPath();

        // Step 1: Create image container
        $containerResponse = Http::post("https://graph.threads.net/v1.0/{$threadsUserId}/threads", [
            'media_type' => 'IMAGE',
            'image_url' => $imageUrl,
            'text' => $text,
            'access_token' => $this->accessToken()
        ]);

        if ($containerResponse->failed()) {
            return $this->buildResponse($containerResponse);
        }

        $containerId = $containerResponse->json()['id'];

        // Step 2: Publish the container
        return $this->publishContainer($threadsUserId, $containerId);
    }

    protected function publishVideoPost(string $text, Media $mediaItem, string $threadsUserId): SocialProviderResponse
    {
        $videoUrl = $mediaItem->isLocalAdapter() ? $mediaItem->getUrl() : $mediaItem->getFullPath();

        // Step 1: Create video container
        $containerResponse = Http::post("https://graph.threads.net/v1.0/{$threadsUserId}/threads", [
            'media_type' => 'VIDEO',
            'video_url' => $videoUrl,
            'text' => $text,
            'access_token' => $this->accessToken()
        ]);

        if ($containerResponse->failed()) {
            return $this->buildResponse($containerResponse);
        }

        $containerId = $containerResponse->json()['id'];

        // Step 2: Poll for video processing status
        $maxAttempts = 60;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            sleep(2);

            $statusResponse = Http::get("https://graph.threads.net/v1.0/{$containerId}", [
                'fields' => 'status,error_message',
                'access_token' => $this->accessToken()
            ]);

            if ($statusResponse->successful()) {
                $status = $statusResponse->json()['status'] ?? '';

                if ($status === 'FINISHED') {
                    break;
                } elseif ($status === 'ERROR') {
                    $errorMessage = $statusResponse->json()['error_message'] ?? 'Video processing failed';
                    return new SocialProviderResponse(SocialProviderResponseStatus::ERROR, [$errorMessage]);
                }
            }

            $attempt++;
        }

        if ($attempt >= $maxAttempts) {
            return new SocialProviderResponse(SocialProviderResponseStatus::ERROR, ['Video processing timeout']);
        }

        // Step 3: Publish the container
        return $this->publishContainer($threadsUserId, $containerId);
    }

    protected function publishContainer(string $threadsUserId, string $containerId): SocialProviderResponse
    {
        $publishResponse = Http::post("https://graph.threads.net/v1.0/{$threadsUserId}/threads_publish", [
            'creation_id' => $containerId,
            'access_token' => $this->accessToken()
        ]);

        return $this->buildResponse($publishResponse, function () use ($publishResponse) {
            return [
                'id' => $publishResponse->json()['id']
            ];
        });
    }

    public function deletePost($id): SocialProviderResponse
    {
        $response = Http::delete("https://graph.threads.net/v1.0/{$id}", [
            'access_token' => $this->accessToken()
        ]);

        return $this->buildResponse($response);
    }
}
