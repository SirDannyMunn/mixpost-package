<?php

namespace Inovector\Mixpost\SocialProviders\YouTube\Concerns;

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
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/userinfo.profile',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
    }

    public function requestAccessToken(array $params): array
    {
        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $params['code'],
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUrl,
                'grant_type' => 'authorization_code'
            ])->throw()->json();

            if (isset($response['access_token'])) {
                return [
                    'access_token' => $response['access_token'],
                    'expires_in' => $response['expires_in'] ?? 3600,
                    'refresh_token' => $response['refresh_token'] ?? null,
                    'scope' => $response['scope'] ?? ''
                ];
            }

            return [
                'error' => $response['error_description'] ?? 'Unknown error'
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
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token'
            ])->throw()->json();

            if (isset($response['access_token'])) {
                return [
                    'access_token' => $response['access_token'],
                    'expires_in' => $response['expires_in'] ?? 3600,
                    'refresh_token' => $refreshToken
                ];
            }

            return [
                'error' => $response['error_description'] ?? 'Unknown error'
            ];
        } catch (RequestException $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}
