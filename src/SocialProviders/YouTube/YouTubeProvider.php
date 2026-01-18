<?php

namespace Inovector\Mixpost\SocialProviders\YouTube;

use Illuminate\Http\Request;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\YouTubeService;
use Inovector\Mixpost\SocialProviders\YouTube\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\YouTube\Concerns\ManagesRateLimit;
use Inovector\Mixpost\SocialProviders\YouTube\Concerns\ManagesResources;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

class YouTubeProvider extends SocialProvider
{
    use ManagesRateLimit;
    use ManagesOAuth;
    use ManagesResources;

    public array $callbackResponseKeys = ['code'];

    public function __construct(Request $request, string $clientId, string $clientSecret, string $redirectUrl, array $values = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $values);
    }

    public static function name(): string
    {
        return 'YouTube';
    }

    public static function service(): string
    {
        return YouTubeService::class;
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.youtube.simultaneous_posting_on_multiple_accounts', true))
            ->minTextChar(0)
            ->maxTextChar(Util::config('social_provider_options.youtube.post_character_limit', 5000))
            ->minVideos(1)
            ->maxVideos(Util::config('social_provider_options.youtube.media_limit.videos', 1))
            ->minPhotos(0)
            ->maxPhotos(0)
            ->allowMixingMediaTypes(false);
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.youtube.com/watch?v={$accountResource->pivot->provider_post_id}";
    }
}
