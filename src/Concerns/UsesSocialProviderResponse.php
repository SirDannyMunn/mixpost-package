<?php

namespace Inovector\Mixpost\Concerns;

use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait UsesSocialProviderResponse
{
    public function response(
        SocialProviderResponseStatus $status,
        ?array                       $context = null,
        bool|string                  $rateLimitAboutToBeExceeded = false,
        int                          $retryAfter = 0,
        bool                         $isAppLevel = false): SocialProviderResponse
    {
        // Handle legacy calls where 3rd argument was the error message string
        $errorMessage = null;
        if (is_string($rateLimitAboutToBeExceeded)) {
            $errorMessage = $rateLimitAboutToBeExceeded;
            $rateLimitAboutToBeExceeded = false;
        }
        
        return new SocialProviderResponse($status, $context ?? [], $rateLimitAboutToBeExceeded, $retryAfter, $isAppLevel, $errorMessage);
    }
}
