<?php

namespace Inovector\Mixpost\SocialProviders\Pinterest\Concerns;

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
                ->get("{$this->apiUrl}/{$this->apiVersion}/user_account")
                ->throw()
                ->json();

            if (isset($response['username'])) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $response['username'],
                    'name' => $response['business_name'] ?? $response['username'],
                    'username' => $response['username'],
                    'image' => $response['profile_image'] ?? ''
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
            
            // Get the first board (or use provided board_id)
            $boardId = $params['board_id'] ?? $this->getDefaultBoard($accessToken);
            if (!$boardId) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'No board available for pinning');
            }

            // Pinterest requires at least one image
            if ($media->isEmpty() || !$media->first()->isImage()) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Pinterest requires at least one image');
            }

            $firstMedia = $media->first();
            
            // Prepare pin data
            $pinData = [
                'board_id' => $boardId,
                'title' => $params['title'] ?? substr($text, 0, 100), // Max 100 chars for title
                'description' => $text,
                'link' => $params['link'] ?? null,
            ];

            // Handle media URL
            $mediaUrl = $firstMedia->isLocalAdapter() ? $firstMedia->getUrl() : $firstMedia->getUrl();
            
            // Pinterest requires a publicly accessible URL for media
            if (filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
                $pinData['media_source'] = [
                    'source_type' => 'image_url',
                    'url' => $mediaUrl
                ];
            } else {
                // For local files, we need to upload as base64
                $imagePath = $firstMedia->getFullPath();
                $imageData = base64_encode(file_get_contents($imagePath));
                $pinData['media_source'] = [
                    'source_type' => 'image_base64',
                    'data' => $imageData,
                    'content_type' => $firstMedia->mime_type
                ];
            }

            $response = Http::withToken($accessToken['access_token'])
                ->post("{$this->apiUrl}/{$this->apiVersion}/pins", $pinData)
                ->throw()
                ->json();

            if (isset($response['id'])) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $response['id']
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to create pin');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    protected function getDefaultBoard(array $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken['access_token'])
                ->get("{$this->apiUrl}/{$this->apiVersion}/boards")
                ->throw()
                ->json();

            if (isset($response['items']) && count($response['items']) > 0) {
                return $response['items'][0]['id'];
            }

            return null;
        } catch (RequestException $e) {
            return null;
        }
    }

    public function deletePost($id): SocialProviderResponse
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken['access_token'])
                ->delete("{$this->apiUrl}/{$this->apiVersion}/pins/{$id}")
                ->throw();

            return $this->response(SocialProviderResponseStatus::OK, []);
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }
}
