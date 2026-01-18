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
            $response = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/{$this->apiVersion}/ugcPosts", [
                    'author' => "urn:li:person:{$personUrn}",
                    'lifecycleState' => 'PUBLISHED',
                    'specificContent' => [
                        'com.linkedin.ugc.ShareContent' => [
                            'shareCommentary' => [
                                'text' => $text
                            ],
                            'shareMediaCategory' => 'NONE'
                        ]
                    ],
                    'visibility' => [
                        'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
                    ]
                ])
                ->throw()
                ->json();

            if (isset($response['id'])) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $response['id']
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to publish text post');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    protected function publishImagePost(string $text, Collection $media, string $personUrn, array $accessToken): SocialProviderResponse
    {
        try {
            $assets = [];

            // Register and upload each image
            foreach ($media as $item) {
                if (!$item->isImage()) {
                    continue;
                }

                // Step 1: Register upload for image
                $registerResponse = Http::withToken($accessToken['access_token'])
                    ->withHeaders([
                        'X-Restli-Protocol-Version' => '2.0.0',
                        'Content-Type' => 'application/json'
                    ])
                    ->post("{$this->apiUrl}/{$this->apiVersion}/assets?action=registerUpload", [
                        'registerUploadRequest' => [
                            'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                            'owner' => "urn:li:person:{$personUrn}",
                            'serviceRelationships' => [
                                [
                                    'relationshipType' => 'OWNER',
                                    'identifier' => 'urn:li:userGeneratedContent'
                                ]
                            ]
                        ]
                    ])
                    ->throw()
                    ->json();

                if (!isset($registerResponse['value']['asset'])) {
                    continue;
                }

                $asset = $registerResponse['value']['asset'];
                $uploadUrl = $registerResponse['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];

                // Step 2: Upload image binary
                $imagePath = $item->isLocalAdapter() ? $item->getFullPath() : $item->getUrl();
                $uploadResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$accessToken['access_token']}",
                    'Content-Type' => $item->mime_type
                ])->put($uploadUrl, file_get_contents($imagePath));

                if ($uploadResponse->successful()) {
                    $assets[] = $asset;
                }
            }

            if (empty($assets)) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to upload images');
            }

            // Step 3: Create post with uploaded images
            $mediaContent = array_map(function ($asset) {
                return [
                    'status' => 'READY',
                    'media' => $asset
                ];
            }, $assets);

            $response = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/{$this->apiVersion}/ugcPosts", [
                    'author' => "urn:li:person:{$personUrn}",
                    'lifecycleState' => 'PUBLISHED',
                    'specificContent' => [
                        'com.linkedin.ugc.ShareContent' => [
                            'shareCommentary' => [
                                'text' => $text
                            ],
                            'shareMediaCategory' => 'IMAGE',
                            'media' => $mediaContent
                        ]
                    ],
                    'visibility' => [
                        'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
                    ]
                ])
                ->throw()
                ->json();

            if (isset($response['id'])) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $response['id']
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to publish image post');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    protected function publishVideoPost(string $text, $media, string $personUrn, array $accessToken): SocialProviderResponse
    {
        try {
            // Step 1: Register video upload
            $registerResponse = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/{$this->apiVersion}/assets?action=registerUpload", [
                    'registerUploadRequest' => [
                        'recipes' => ['urn:li:digitalmediaRecipe:feedshare-video'],
                        'owner' => "urn:li:person:{$personUrn}",
                        'serviceRelationships' => [
                            [
                                'relationshipType' => 'OWNER',
                                'identifier' => 'urn:li:userGeneratedContent'
                            ]
                        ]
                    ]
                ])
                ->throw()
                ->json();

            if (!isset($registerResponse['value']['asset'])) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to register video upload');
            }

            $asset = $registerResponse['value']['asset'];
            $uploadUrl = $registerResponse['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];

            // Step 2: Upload video binary
            $videoPath = $media->isLocalAdapter() ? $media->getFullPath() : $media->getUrl();
            $uploadResponse = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken['access_token']}",
                'Content-Type' => $media->mime_type
            ])->put($uploadUrl, file_get_contents($videoPath));

            if (!$uploadResponse->successful()) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to upload video file');
            }

            // Step 3: Create post with uploaded video
            $response = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json'
                ])
                ->post("{$this->apiUrl}/{$this->apiVersion}/ugcPosts", [
                    'author' => "urn:li:person:{$personUrn}",
                    'lifecycleState' => 'PUBLISHED',
                    'specificContent' => [
                        'com.linkedin.ugc.ShareContent' => [
                            'shareCommentary' => [
                                'text' => $text
                            ],
                            'shareMediaCategory' => 'VIDEO',
                            'media' => [
                                [
                                    'status' => 'READY',
                                    'media' => $asset
                                ]
                            ]
                        ]
                    ],
                    'visibility' => [
                        'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
                    ]
                ])
                ->throw()
                ->json();

            if (isset($response['id'])) {
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $response['id']
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to publish video post');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    public function deletePost($id): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
