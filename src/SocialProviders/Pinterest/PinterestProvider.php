<?php

namespace Inovector\Mixpost\SocialProviders\Pinterest;

use Illuminate\Http\Request;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\PinterestService;
use Inovector\Mixpost\SocialProviders\Pinterest\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\Pinterest\Concerns\ManagesRateLimit;
use Inovector\Mixpost\SocialProviders\Pinterest\Concerns\ManagesResources;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

class PinterestProvider extends SocialProvider
{
    use ManagesRateLimit;
    use ManagesOAuth;
    use ManagesResources;

    public array $callbackResponseKeys = ['code'];

    protected string $apiVersion = 'v5';
    protected string $apiUrl = 'https://api.pinterest.com';

    public function __construct(Request $request, string $clientId, string $clientSecret, string $redirectUrl, array $values = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $values);
    }

    public static function name(): string
    {
        return 'Pinterest';
    }

    public static function service(): string
    {
        return PinterestService::class;
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.pinterest.simultaneous_posting_on_multiple_accounts', true))
            ->minTextChar(0)
            ->maxTextChar(Util::config('social_provider_options.pinterest.post_character_limit', 500))
            ->minVideos(0)
            ->maxVideos(0)
            ->minPhotos(1)
            ->maxPhotos(Util::config('social_provider_options.pinterest.media_limit.photos', 1))
            ->allowMixingMediaTypes(false);
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.pinterest.com/{$accountResource->provider_id}/";
    }
}
