<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Concerns;

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesInstagramOAuth
{
    // Instagram has its own Graph API endpoint (not Facebook's)
    protected string $instagramApiUrl = 'https://graph.instagram.com';
    
    public function getAuthUrl(): string
    {
        // Use encrypted state from values if provided (cross-domain OAuth)
        // Falls back to csrf_token for standard Mixpost admin flows
        $state = $this->values['oauth_state'] ?? csrf_token();
        
        // Instagram Business API scopes - all must use instagram_business_ prefix
        $instagramScopes = implode(',', [
            'instagram_business_basic',
            'instagram_business_manage_messages',
            'instagram_business_manage_comments',
            'instagram_business_content_publish',
            'instagram_business_manage_insights',
        ]);
        
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => $instagramScopes,
            'response_type' => 'code',
            'state' => $state,
        ];

        return 'https://www.instagram.com/oauth/authorize?' . http_build_query($params);
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

        // Exchange short-lived token for long-lived token using Instagram's Graph API
        $longLivedResponse = Http::get("$this->instagramApiUrl/access_token", [
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
        $response = Http::get("$this->instagramApiUrl/refresh_access_token", [
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
        $tokenData = $this->getAccessToken();
        $accessToken = $tokenData['access_token'] ?? null;
        
        \Illuminate\Support\Facades\Log::info('Instagram getEntities called', [
            'has_access_token' => !empty($accessToken),
            'token_data_keys' => array_keys($tokenData ?? []),
            'api_url' => $this->instagramApiUrl,
        ]);
        
        if (empty($accessToken)) {
            \Illuminate\Support\Facades\Log::error('Instagram getEntities: No access token available');
            return $this->response(
                \Inovector\Mixpost\Enums\SocialProviderResponseStatus::ERROR,
                [],
                'No access token available'
            );
        }
        
        // For Instagram Business API, we need to get the user's Instagram accounts
        // First, get the user info
        $meResponse = Http::get("$this->instagramApiUrl/me", [
            'fields' => 'user_id,username,account_type,profile_picture_url',
            'access_token' => $accessToken
        ]);
        
        \Illuminate\Support\Facades\Log::info('Instagram /me response', [
            'status' => $meResponse->status(),
            'body' => $meResponse->json(),
        ]);
        
        if ($meResponse->failed()) {
            return $this->response(
                \Inovector\Mixpost\Enums\SocialProviderResponseStatus::ERROR,
                [],
                $meResponse->json('error.message') ?? 'Failed to fetch Instagram user'
            );
        }
        
        $userData = $meResponse->json();
        
        // Instagram API returns the user directly - wrap as entity
        $accounts = [];
        
        if (isset($userData['user_id'])) {
            $account = [
                'id' => $userData['user_id'],
                'name' => $userData['username'] ?? 'Instagram Account',
                'username' => $userData['username'] ?? '',
                'image' => $userData['profile_picture_url'] ?? '',
            ];

            if ($withAccessToken) {
                $account['access_token'] = [
                    'access_token' => $accessToken
                ];
            }

            $accounts[] = $account;
        }
        
        \Illuminate\Support\Facades\Log::info('Instagram entities result', [
            'count' => count($accounts),
            'accounts' => $accounts,
            'user_data_keys' => array_keys($userData ?? []),
        ]);

        // If no accounts found, it might be an issue with the response format
        if (empty($accounts)) {
            return $this->response(
                \Inovector\Mixpost\Enums\SocialProviderResponseStatus::ERROR,
                [],
                'No Instagram accounts found. user_id may not be in response. Keys: ' . implode(', ', array_keys($userData ?? []))
            );
        }

        return $this->response(
            \Inovector\Mixpost\Enums\SocialProviderResponseStatus::OK,
            $accounts
        );
    }
}
