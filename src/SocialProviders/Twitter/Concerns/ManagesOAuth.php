<?php

namespace Inovector\Mixpost\SocialProviders\Twitter\Concerns;

use Illuminate\Support\Facades\Cache;

trait ManagesOAuth
{
    public function getAuthUrl(): string
    {
        // For OAuth 1.0a, we need to pass state through the callback URL
        // since OAuth 1.0a doesn't have a native state parameter
        $callbackUrl = $this->redirectUrl;
        
        // If we have an encrypted state (cross-domain OAuth), append it to callback URL
        if (!empty($this->values['oauth_state'])) {
            $callbackUrl .= (str_contains($callbackUrl, '?') ? '&' : '?') . 
                'state=' . urlencode($this->values['oauth_state']);
        }
        
        $result = $this->connection->oauth('oauth/request_token', [
            'x_auth_access_type' => 'write',
            'oauth_callback' => $callbackUrl
        ]);

        // Store the oauth_token_secret temporarily - we'll need it for the access token request
        // Twitter OAuth 1.0a requires the request token secret to get the access token
        Cache::put(
            "twitter_oauth_secret:{$result['oauth_token']}", 
            $result['oauth_token_secret'], 
            now()->addMinutes(15)
        );

        return $this->connection->url('oauth/authorize', ['oauth_token' => $result['oauth_token']]);
    }

    public function requestAccessToken(array $params): array
    {
        // Retrieve the stored oauth_token_secret from cache
        $oauthTokenSecret = Cache::pull("twitter_oauth_secret:{$params['oauth_token']}");
        
        if ($oauthTokenSecret) {
            // Set the request token before exchanging for access token
            $this->connection->setOauthToken($params['oauth_token'], $oauthTokenSecret);
        }
        
        $result = $this->connection->oauth('oauth/access_token', [
            'oauth_token' => $params['oauth_token'], 
            'oauth_verifier' => $params['oauth_verifier']
        ]);

        return [
            'oauth_token' => $result['oauth_token'],
            'oauth_token_secret' => $result['oauth_token_secret']
        ];
    }

    // Overwrite setAccessToken to use Twitter SDK
    public function setAccessToken(array $token = []): void
    {
        $this->connection->setOauthToken($token['oauth_token'], $token['oauth_token_secret']);
    }

    // Overwrite useAccessToken to use Twitter SDK
    public function useAccessToken(array $token = []): static
    {
        $this->connection->setOauthToken($token['oauth_token'], $token['oauth_token_secret']);

        return $this;
    }
}
