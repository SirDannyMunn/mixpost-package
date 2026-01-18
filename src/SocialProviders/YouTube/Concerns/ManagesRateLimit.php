<?php

namespace Inovector\Mixpost\SocialProviders\YouTube\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesRateLimit
{
    /**
     * @param $response Response
     */
    public function buildResponse($response, Closure $okResult = null): SocialProviderResponse
    {
        if (in_array($response->status(), [200, 201, 202])) {
            return $this->response(
                SocialProviderResponseStatus::OK,
                $okResult ? $okResult() : ($response->json() ?? [])
            );
        }

        if ($response->status() === 429 || $response->status() === 403) {
            $json = $response->json();
            
            // Check if it's a quota exceeded error
            if (isset($json['error']['errors'][0]['reason']) && 
                in_array($json['error']['errors'][0]['reason'], ['quotaExceeded', 'rateLimitExceeded'])) {
                return $this->response(
                    SocialProviderResponseStatus::EXCEEDED_RATE_LIMIT,
                    $this->rateLimitExceedContext(3600)
                );
            }
        }

        if ($response->status() === 401) {
            return $this->response(
                SocialProviderResponseStatus::UNAUTHORIZED,
                ['access_token_expired']
            );
        }

        return $this->response(
            SocialProviderResponseStatus::ERROR,
            $response->json() ?? []
        );
    }

    public function isRateLimitExceeded(): SocialProviderResponse
    {
        return $this->response(SocialProviderResponseStatus::OK, []);
    }
}
