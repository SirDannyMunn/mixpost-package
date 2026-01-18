<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Concerns;

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesInstagramOAuth
{
    public function getAuthUrl(): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->scope,
            'response_type' => 'code',
            'config_id' => '',
        ];

        return 'https://api.instagram.com/oauth/authorize?' . http_build_query($params);
    }

    public function requestAccessToken(array $params = []): array
    {
        $response = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
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
        $longLivedResponse = Http::get("$this->apiUrl/$this->apiVersion/access_token", [
            'grant_type' => 'ig_exchange_token',
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
        $response = Http::get("$this->apiUrl/$this->apiVersion/refresh_access_token", [
            'grant_type' => 'ig_refresh_token',
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

    public function getEntities(bool $withAccessToken = false): SocialProviderResponse
    {
        $response = Http::get("$this->apiUrl/$this->apiVersion/me/accounts", [
            'fields' => 'id,name,instagram_business_account' . ($withAccessToken ? ',access_token' : ''),
            'access_token' => $this->getAccessToken()['access_token'],
            'limit' => 200
        ]);

        return $this->buildResponse($response, function () use ($response, $withAccessToken) {
            $accounts = collect();

            foreach ($response->json('data', []) as $page) {
                if (!isset($page['instagram_business_account']['id'])) {
                    continue;
                }

                $igAccountId = $page['instagram_business_account']['id'];

                // Get Instagram account details
                $igResponse = Http::get("$this->apiUrl/$this->apiVersion/$igAccountId", [
                    'fields' => 'id,username,profile_picture_url',
                    'access_token' => $this->getAccessToken()['access_token']
                ]);

                if ($igResponse->successful()) {
                    $igData = $igResponse->json();

                    $account = [
                        'id' => $igData['id'],
                        'name' => $igData['username'],
                        'username' => $igData['username'],
                        'image' => $igData['profile_picture_url'] ?? '',
                    ];

                    if ($withAccessToken) {
                        $account['access_token'] = [
                            'access_token' => $this->getAccessToken()['access_token']
                        ];
                    }

                    $accounts->push($account);
                }
            }

            return $accounts->toArray();
        });
    }
}
