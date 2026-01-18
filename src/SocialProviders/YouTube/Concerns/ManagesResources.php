<?php

namespace Inovector\Mixpost\SocialProviders\YouTube\Concerns;

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

            // Get channel information
            $response = Http::withToken($accessToken['access_token'])
                ->get('https://www.googleapis.com/youtube/v3/channels', [
                    'part' => 'snippet,contentDetails',
                    'mine' => 'true'
                ])
                ->throw()
                ->json();

            if (isset($response['items'][0])) {
                $channel = $response['items'][0];
                return $this->response(SocialProviderResponseStatus::OK, [
                    'id' => $channel['id'],
                    'name' => $channel['snippet']['title'] ?? '',
                    'username' => $channel['snippet']['customUrl'] ?? $channel['id'],
                    'image' => $channel['snippet']['thumbnails']['default']['url'] ?? ''
                ]);
            }

            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to retrieve channel information');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        try {
            $accessToken = $this->getAccessToken();

            // YouTube requires video media
            if ($media->isEmpty()) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'YouTube requires video content');
            }

            $video = $media->first();

            if (!$video->isVideo()) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'YouTube only supports video uploads');
            }

            return $this->uploadVideo($video, $text, $accessToken, $params);
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    protected function uploadVideo($video, string $text, array $accessToken, array $params = []): SocialProviderResponse
    {
        try {
            // Parse title and description from text
            $lines = explode("\n", $text, 2);
            $title = $lines[0];
            $description = $lines[1] ?? '';

            // Determine if this is a Short (< 60 seconds typically)
            $isShort = isset($params['is_short']) ? $params['is_short'] : false;

            // Build video metadata
            $metadata = [
                'snippet' => [
                    'title' => substr($title, 0, 100), // YouTube title limit
                    'description' => substr($description, 0, 5000), // YouTube description limit
                    'categoryId' => $params['category_id'] ?? '22', // Default to "People & Blogs"
                ],
                'status' => [
                    'privacyStatus' => $params['privacy'] ?? 'public',
                    'selfDeclaredMadeForKids' => $params['made_for_kids'] ?? false,
                ]
            ];

            // Add tags if provided
            if (isset($params['tags']) && is_array($params['tags'])) {
                $metadata['snippet']['tags'] = $params['tags'];
            }

            // Add Shorts-specific metadata if applicable
            if ($isShort) {
                $metadata['snippet']['title'] = '#Shorts ' . $metadata['snippet']['title'];
            }

            // Step 1: Initialize resumable upload
            $initResponse = Http::withToken($accessToken['access_token'])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Upload-Content-Type' => $video->mime_type
                ])
                ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata)
                ->throw();

            // Get resumable upload URL from Location header
            $uploadUrl = $initResponse->header('Location');
            
            if (!$uploadUrl) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to get upload URL');
            }

            // Step 2: Upload video in chunks
            $videoPath = $video->isLocalAdapter() ? $video->getFullPath() : $video->getUrl();
            $videoSize = filesize($videoPath);
            $chunkSize = 5 * 1024 * 1024; // 5MB chunks
            $handle = fopen($videoPath, 'rb');

            if (!$handle) {
                return $this->response(SocialProviderResponseStatus::ERROR, null, 'Failed to read video file');
            }

            $offset = 0;
            $uploadedBytes = 0;

            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $chunkLength = strlen($chunk);
                $uploadedBytes += $chunkLength;

                $rangeEnd = min($offset + $chunkLength - 1, $videoSize - 1);

                $uploadResponse = Http::withHeaders([
                    'Content-Type' => $video->mime_type,
                    'Content-Range' => "bytes {$offset}-{$rangeEnd}/{$videoSize}"
                ])->withBody($chunk, $video->mime_type)
                ->put($uploadUrl);

                // Check if upload is complete (status 200 or 201)
                if (in_array($uploadResponse->status(), [200, 201])) {
                    fclose($handle);
                    
                    $result = $uploadResponse->json();
                    
                    if (isset($result['id'])) {
                        return $this->response(SocialProviderResponseStatus::OK, [
                            'id' => $result['id']
                        ]);
                    }
                    
                    return $this->response(SocialProviderResponseStatus::ERROR, null, 'Video uploaded but no ID returned');
                }

                // Continue if status is 308 (Resume Incomplete)
                if ($uploadResponse->status() !== 308) {
                    fclose($handle);
                    return $this->response(
                        SocialProviderResponseStatus::ERROR, 
                        null, 
                        'Video upload failed with status: ' . $uploadResponse->status()
                    );
                }

                $offset += $chunkLength;
            }

            fclose($handle);
            
            return $this->response(SocialProviderResponseStatus::ERROR, null, 'Video upload incomplete');
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }

    public function deletePost($id): SocialProviderResponse
    {
        try {
            $accessToken = $this->getAccessToken();

            Http::withToken($accessToken['access_token'])
                ->delete("https://www.googleapis.com/youtube/v3/videos", [
                    'id' => $id
                ])
                ->throw();

            return $this->response(SocialProviderResponseStatus::OK, []);
        } catch (RequestException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, null, $e->getMessage());
        }
    }
}
