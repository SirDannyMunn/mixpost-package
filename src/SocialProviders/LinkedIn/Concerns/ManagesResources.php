<?php

namespace Inovector\Mixpost\SocialProviders\LinkedIn\Concerns;

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
                ->get("{$this->apiUrl}/{$this->apiVersion}/userinfo")
                ->throw()
                ->json();

            if (isset($response['sub'])) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $response['sub'],
                    'name' => $response['name'] ?? '',
                    'username' => $response['email'] ?? '',
                    'image' => $response['picture'] ?? ''
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
            
            // Get person URN for the authenticated user
            $personUrn = $this->getPersonUrn($accessToken);
            if (!$personUrn) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to retrieve person URN');
            }

            // Handle different media types
            if ($media->isEmpty()) {
                return $this->publishTextPost($text, $personUrn, $accessToken);
            }

            $firstMedia = $media->first();

            if ($firstMedia->isImage()) {
                return $this->publishImagePost($text, $media, $personUrn, $accessToken);
            }

            if ($firstMedia->isVideo()) {
                return $this->publishVideoPost($text, $firstMedia, $personUrn, $accessToken);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Unsupported media type for LinkedIn');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    protected function getPersonUrn(array $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken['access_token'])
                ->get("{$this->apiUrl}/{$this->apiVersion}/userinfo")
                ->throw()
                ->json();

            return $response['sub'] ?? null;
        } catch (RequestException $e) {
            return null;
        }
    }

    protected function publishTextPost(string $text, string $personUrn, array $accessToken): SocialProviderResponse
    {
        try {
            // Use the new Posts API (v2/posts) instead of deprecated ugcPosts
            $httpResponse = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'LinkedIn-Version' => '202601',
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/rest/posts", [
                    'author' => "urn:li:person:{$personUrn}",
                    'commentary' => $text,
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => []
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false
                ]);
            
            $response = $httpResponse->json();
            
            if ($httpResponse->failed()) {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    'http_status' => $httpResponse->status(),
                    'response_body' => $response,
                    'request_url' => "{$this->apiUrl}/rest/posts",
                ], 'LinkedIn API error: ' . ($response['message'] ?? $httpResponse->status()));
            }

            // The new Posts API returns the post URN in the x-restli-id header
            $postId = $httpResponse->header('x-restli-id') ?? ($response['id'] ?? null);
            
            if ($postId) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $postId,
                    'http_status' => $httpResponse->status(),
                    'full_response' => $response,
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, [
                'http_status' => $httpResponse->status(),
                'response_body' => $response,
                'headers' => $httpResponse->headers(),
            ], 'Failed to publish text post - no ID returned');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'exception' => get_class($e),
                'http_status' => $e->response?->status(),
                'response_body' => $e->response?->json(),
            ], $e->getMessage());
        }
    }

    protected function publishImagePost(string $text, Collection $media, string $personUrn, array $accessToken): SocialProviderResponse
    {
        try {
            $imageUrns = [];

            // Register and upload each image using the new Images API
            foreach ($media as $item) {
                if (!$item->isImage()) {
                    continue;
                }

                // Step 1: Initialize image upload
                $initResponse = Http::withToken($accessToken['access_token'])
                    ->withHeaders([
                        'LinkedIn-Version' => '202601',
                        'X-Restli-Protocol-Version' => '2.0.0',
                        'Content-Type' => 'application/json'
                    ])
                    ->post("{$this->apiUrl}/rest/images?action=initializeUpload", [
                        'initializeUploadRequest' => [
                            'owner' => "urn:li:person:{$personUrn}"
                        ]
                    ]);

                if ($initResponse->failed()) {
                    continue;
                }

                $initData = $initResponse->json();
                $uploadUrl = $initData['value']['uploadUrl'] ?? null;
                $imageUrn = $initData['value']['image'] ?? null;

                if (!$uploadUrl || !$imageUrn) {
                    continue;
                }

                // Step 2: Upload image binary
                $imagePath = $item->isLocalAdapter() ? $item->getFullPath() : $item->getUrl();
                $uploadResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$accessToken['access_token']}",
                ])->attach('file', file_get_contents($imagePath), 'image')->put($uploadUrl, file_get_contents($imagePath));

                if ($uploadResponse->successful()) {
                    $imageUrns[] = $imageUrn;
                }
            }

            if (empty($imageUrns)) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to upload images');
            }

            // Step 3: Create post with uploaded images using new Posts API
            $mediaContent = array_map(function ($imageUrn) {
                return [
                    'id' => $imageUrn
                ];
            }, $imageUrns);

            $httpResponse = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'LinkedIn-Version' => '202601',
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/rest/posts", [
                    'author' => "urn:li:person:{$personUrn}",
                    'commentary' => $text,
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => []
                    ],
                    'content' => [
                        'multiImage' => [
                            'images' => $mediaContent
                        ]
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false
                ]);

            $response = $httpResponse->json();
            $postId = $httpResponse->header('x-restli-id') ?? ($response['id'] ?? null);

            if ($postId) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $postId,
                    'http_status' => $httpResponse->status(),
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, [
                'http_status' => $httpResponse->status(),
                'response_body' => $response,
            ], 'Failed to publish image post');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'exception' => get_class($e),
                'http_status' => $e->response?->status(),
                'response_body' => $e->response?->json(),
            ], $e->getMessage());
        }
    }

    protected function publishVideoPost(string $text, $media, string $personUrn, array $accessToken): SocialProviderResponse
    {
        try {
            // Step 1: Initialize video upload using the new Videos API
            $initResponse = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'LinkedIn-Version' => '202601',
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/rest/videos?action=initializeUpload", [
                    'initializeUploadRequest' => [
                        'owner' => "urn:li:person:{$personUrn}",
                        'fileSizeBytes' => $media->size,
                        'uploadCaptions' => false,
                        'uploadThumbnail' => false
                    ]
                ]);

            if ($initResponse->failed()) {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    'http_status' => $initResponse->status(),
                    'response_body' => $initResponse->json(),
                ], 'Failed to initialize video upload');
            }

            $initData = $initResponse->json();
            $uploadUrl = $initData['value']['uploadInstructions'][0]['uploadUrl'] ?? null;
            $videoUrn = $initData['value']['video'] ?? null;

            if (!$uploadUrl || !$videoUrn) {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    'response_body' => $initData,
                ], 'Failed to get video upload URL');
            }

            // Step 2: Upload video binary
            $videoPath = $media->isLocalAdapter() ? $media->getFullPath() : $media->getUrl();
            $uploadResponse = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken['access_token']}",
                'Content-Type' => 'application/octet-stream',
            ])->withBody(file_get_contents($videoPath), 'application/octet-stream')
              ->put($uploadUrl);

            if (!$uploadResponse->successful()) {
                return $this->response(SocialProviderResponseStatus::ERROR, [
                    'http_status' => $uploadResponse->status(),
                ], 'Failed to upload video file');
            }

            // Step 3: Finalize video upload
            $finalizeResponse = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'LinkedIn-Version' => '202601',
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/rest/videos?action=finalizeUpload", [
                    'finalizeUploadRequest' => [
                        'video' => $videoUrn,
                        'uploadToken' => '',
                        'uploadedPartIds' => []
                    ]
                ]);

            // Step 4: Create post with uploaded video using new Posts API
            $httpResponse = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'LinkedIn-Version' => '202601',
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/rest/posts", [
                    'author' => "urn:li:person:{$personUrn}",
                    'commentary' => $text,
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => []
                    ],
                    'content' => [
                        'media' => [
                            'id' => $videoUrn
                        ]
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false
                ]);

            $response = $httpResponse->json();
            $postId = $httpResponse->header('x-restli-id') ?? ($response['id'] ?? null);

            if ($postId) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $postId,
                    'http_status' => $httpResponse->status(),
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, [
                'http_status' => $httpResponse->status(),
                'response_body' => $response,
            ], 'Failed to publish video post');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'exception' => get_class($e),
                'http_status' => $e->response?->status(),
                'response_body' => $e->response?->json(),
            ], $e->getMessage());
        }
    }

    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
