<?php

namespace Inovector\Mixpost\SocialProviders\TikTok;

use Illuminate\Http\Request;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\TikTokService;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesRateLimit;
use Inovector\Mixpost\SocialProviders\TikTok\Concerns\ManagesResources;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

class TikTokProvider extends SocialProvider
{
    use ManagesRateLimit;
    use ManagesOAuth;
    use ManagesResources;

    public array $callbackResponseKeys = ['code'];

    protected string $apiVersion = 'v2';
    protected string $apiUrl = 'https://open.tiktokapis.com';

    public function __construct(Request $request, string $clientId, string $clientSecret, string $redirectUrl, array $values = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $values);
    }

    public static function name(): string
    {
        return 'TikTok';
    }

    public static function service(): string
    {
        return TikTokService::class;
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.tiktok.simultaneous_posting_on_multiple_accounts', true))
            ->minTextChar(0)
            ->maxTextChar(Util::config('social_provider_options.tiktok.post_character_limit', 2200))
            ->minVideos(1)
            ->maxVideos(Util::config('social_provider_options.tiktok.media_limit.videos', 1))
            ->minPhotos(1)
            ->maxPhotos(Util::config('social_provider_options.tiktok.media_limit.photos', 35))
            ->allowMixingMediaTypes(Util::config('social_provider_options.tiktok.allow_mixing', false));
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.tiktok.com/@{$accountResource->username}";
    }
}
