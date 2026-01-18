<?php

namespace Inovector\Mixpost\SocialProviders\LinkedIn;

use Illuminate\Http\Request;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\LinkedInService;
use Inovector\Mixpost\SocialProviders\LinkedIn\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\LinkedIn\Concerns\ManagesRateLimit;
use Inovector\Mixpost\SocialProviders\LinkedIn\Concerns\ManagesResources;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

class LinkedInProvider extends SocialProvider
{
    use ManagesRateLimit;
    use ManagesOAuth;
    use ManagesResources;

    public array $callbackResponseKeys = ['code'];

    protected string $apiVersion = 'v2';
    protected string $apiUrl = 'https://api.linkedin.com';

    public function __construct(Request $request, string $clientId, string $clientSecret, string $redirectUrl, array $values = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $values);
    }

    public static function name(): string
    {
        return 'LinkedIn';
    }

    public static function service(): string
    {
        return LinkedInService::class;
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.linkedin.simultaneous_posting_on_multiple_accounts', true))
            ->minTextChar(0)
            ->maxTextChar(Util::config('social_provider_options.linkedin.post_character_limit', 3000))
            ->minVideos(0)
            ->maxVideos(Util::config('social_provider_options.linkedin.media_limit.videos', 1))
            ->minPhotos(0)
            ->maxPhotos(Util::config('social_provider_options.linkedin.media_limit.photos', 9))
            ->allowMixingMediaTypes(Util::config('social_provider_options.linkedin.allow_mixing', false));
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.linkedin.com/feed/";
    }
}
