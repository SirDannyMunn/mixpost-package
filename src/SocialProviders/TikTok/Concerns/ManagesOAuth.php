<?php

namespace Inovector\Mixpost\SocialProviders\TikTok\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

trait ManagesOAuth
{
    public function getAuthUrl(): string
    {
        // Use encrypted state from values if provided (cross-domain OAuth)
        // Falls back to csrf_token for standard Mixpost admin flows
        $state = $this->values['oauth_state'] ?? csrf_token();
        
        $params = http_build_query([
            'client_key' => $this->clientId,
            'scope' => 'user.info.basic,video.publish',
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUrl,
            'state' => $state
        ]);

        return "https://www.tiktok.com/v2/auth/authorize/?{$params}";
    }

    public function requestAccessToken(array $params): array
    {
        try {
            $requestData = [
                'client_key' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $params['code'],
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUrl
            ];
            
            $response = Http::asForm()->post("{$this->apiUrl}/{$this->apiVersion}/oauth/token/", $requestData)->throw()->json();

            // TikTok API v2 returns tokens directly (not wrapped in 'data')
            // Handle both formats for compatibility
            $tokenData = $response['data'] ?? $response;

            if (isset($tokenData['access_token'])) {
                return [
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'],
                    'expires_in' => $tokenData['expires_in'],
                    'open_id' => $tokenData['open_id']
                ];
            }

            return [
                'error' => $tokenData['error_description'] ?? $response['error']['message'] ?? 'Unknown error'
            ];
        } catch (RequestException $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        try {
            $response = Http::asForm()->post("{$this->apiUrl}/{$this->apiVersion}/oauth/token/", [
                'client_key' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ])->throw()->json();

            // TikTok API v2 returns tokens directly (not wrapped in 'data')
            $tokenData = $response['data'] ?? $response;

            if (isset($tokenData['access_token'])) {
                return [
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'],
                    'expires_in' => $tokenData['expires_in'],
                    'open_id' => $tokenData['open_id']
                ];
            }

            return [
                'error' => $tokenData['error_description'] ?? 'Unknown error'
            ];
        } catch (RequestException $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}
