<?php

namespace Inovector\Mixpost\SocialProviders\Meta;

use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\InstagramService;
use Inovector\Mixpost\SocialProviders\Meta\Concerns\ManagesInstagramOAuth;
use Inovector\Mixpost\SocialProviders\Meta\Concerns\ManagesInstagramResources;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

class InstagramProvider extends MetaProvider
{
    use ManagesInstagramOAuth;
    use ManagesInstagramResources;

    public bool $onlyUserAccount = false;

    public static function name(): string
    {
        return 'Instagram';
    }

    public static function service(): string
    {
        return InstagramService::class;
    }

    protected function accessToken(): string
    {
        return $this->getAccessToken()['access_token'];
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.instagram.simultaneous_posting_on_multiple_accounts', true))
            ->minTextChar(0)
            ->maxTextChar(Util::config('social_provider_options.instagram.post_character_limit', 2200))
            ->minVideos(1)
            ->maxVideos(Util::config('social_provider_options.instagram.media_limit.videos', 1))
            ->minPhotos(1)
            ->maxPhotos(Util::config('social_provider_options.instagram.media_limit.photos', 10))
            ->allowMixingMediaTypes(Util::config('social_provider_options.instagram.allow_mixing', true));
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.instagram.com/p/{$accountResource->pivot->provider_post_id}";
    }
}
