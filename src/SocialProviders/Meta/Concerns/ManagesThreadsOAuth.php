<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Concerns;

use Illuminate\Support\Facades\Http;

trait ManagesThreadsOAuth
{
    public function getAuthUrl(): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => 'threads_basic,threads_content_publish',
            'response_type' => 'code',
        ];

        return 'https://threads.net/oauth/authorize?' . http_build_query($params);
    }

    public function requestAccessToken(array $params = []): array
    {
        $response = Http::asForm()->post('https://graph.threads.net/oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUrl,
            'code' => $params['code']
        ]);

        if ($response->failed()) {
            return [];
        }

        $shortLivedToken = $response->json();

        // Exchange short-lived token for long-lived token
        $longLivedResponse = Http::get('https://graph.threads.net/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => $this->clientSecret,
            'access_token' => $shortLivedToken['access_token']
        ]);

        if ($longLivedResponse->failed()) {
            return $shortLivedToken;
        }

        $longLivedToken = $longLivedResponse->json();

        return [
            'access_token' => $longLivedToken['access_token'],
            'expires_in' => $longLivedToken['expires_in'] ?? 5184000, // 60 days default
        ];
    }

    public function refreshAccessToken(string $accessToken): array
    {
        $response = Http::get('https://graph.threads.net/refresh_access_token', [
            'grant_type' => 'th_refresh_token',
            'access_token' => $accessToken
        ]);

        if ($response->failed()) {
            return [];
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'expires_in' => $data['expires_in'] ?? 5184000,
        ];
    }
}
