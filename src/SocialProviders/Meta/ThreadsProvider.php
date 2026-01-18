<?php

namespace Inovector\Mixpost\SocialProviders\Meta;

use Inovector\Mixpost\Http\Resources\AccountResource;
use Inovector\Mixpost\Services\ThreadsService;
use Inovector\Mixpost\SocialProviders\Meta\Concerns\ManagesThreadsOAuth;
use Inovector\Mixpost\SocialProviders\Meta\Concerns\ManagesThreadsResources;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Util;

class ThreadsProvider extends MetaProvider
{
    use ManagesThreadsOAuth;
    use ManagesThreadsResources;

    public bool $onlyUserAccount = false;

    public static function name(): string
    {
        return 'Threads';
    }

    public static function service(): string
    {
        return ThreadsService::class;
    }

    protected function accessToken(): string
    {
        return $this->getAccessToken()['access_token'];
    }

    public static function postConfigs(): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(Util::config('social_provider_options.threads.simultaneous_posting_on_multiple_accounts'))
            ->minTextChar(1)
            ->minPhotos(1)
            ->minVideos(1)
            ->maxTextChar(Util::config('social_provider_options.threads.post_character_limit'))
            ->maxPhotos(Util::config('social_provider_options.threads.media_limit.photos'))
            ->maxVideos(Util::config('social_provider_options.threads.media_limit.videos'))
            ->allowMixingMediaTypes(Util::config('social_provider_options.threads.allow_mixing'));
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.threads.net/@{$accountResource->username}/post/{$accountResource->pivot->provider_post_id}";
    }
}
