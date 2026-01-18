<?php

namespace Inovector\Mixpost\SocialProviders\Pinterest\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

trait ManagesOAuth
{
    public function getAuthUrl(): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => 'boards:read,pins:read,pins:write',
            'state' => csrf_token()
        ]);

        return "https://www.pinterest.com/oauth/?{$params}";
    }

    public function requestAccessToken(array $params): array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->post('https://api.pinterest.com/v5/oauth/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $params['code'],
                    'redirect_uri' => $this->redirectUrl
                ])->throw()->json();

            if (isset($response['access_token'])) {
                return [
                    'access_token' => $response['access_token'],
                    'expires_in' => $response['expires_in'] ?? 31536000,
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
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->post('https://api.pinterest.com/v5/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken
                ])->throw()->json();

            if (isset($response['access_token'])) {
                return [
                    'access_token' => $response['access_token'],
                    'expires_in' => $response['expires_in'] ?? 31536000,
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
