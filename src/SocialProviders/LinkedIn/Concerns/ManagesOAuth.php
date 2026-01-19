<?php

namespace Inovector\Mixpost\SocialProviders\LinkedIn\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

trait ManagesOAuth
{
    public function getAuthUrl(): string
    {
        // Use encrypted state from values if provided (cross-domain OAuth)
        // Falls back to csrf_token for standard Mixpost admin flows
        $state = $this->values['oauth_state'] ?? csrf_token();
        
        // Scopes required for the new Posts API:
        // - openid, profile, email: Required for user info
        // - w_member_social: Required for posting content on behalf of the member
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => 'openid profile email w_member_social',
            'state' => $state
        ]);

        return "https://www.linkedin.com/oauth/v2/authorization?{$params}";
    }

    public function requestAccessToken(array $params): array
    {
        try {
            $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'authorization_code',
                'code' => $params['code'],
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUrl
            ])->throw()->json();

            if (isset($response['access_token'])) {
                return [
                    'access_token' => $response['access_token'],
                    'expires_in' => $response['expires_in'] ?? 5184000,
                    'refresh_token' => $response['refresh_token'] ?? null,
                    'refresh_token_expires_in' => $response['refresh_token_expires_in'] ?? null
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
            $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret
            ])->throw()->json();

            if (isset($response['access_token'])) {
                return [
                    'access_token' => $response['access_token'],
                    'expires_in' => $response['expires_in'] ?? 5184000,
                    'refresh_token' => $response['refresh_token'] ?? $refreshToken,
                    'refresh_token_expires_in' => $response['refresh_token_expires_in'] ?? null
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
