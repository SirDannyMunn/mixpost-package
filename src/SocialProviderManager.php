<?php

namespace Inovector\Mixpost;

use Inovector\Mixpost\Abstracts\SocialProviderManager as SocialProviderManagerAbstract;
use Inovector\Mixpost\Facades\ServiceManager;
use Inovector\Mixpost\SocialProviders\Meta\FacebookPageProvider;
use Inovector\Mixpost\SocialProviders\Meta\InstagramProvider;
use Inovector\Mixpost\SocialProviders\Meta\ThreadsProvider;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;
use Inovector\Mixpost\SocialProviders\Mastodon\MastodonProvider;
use Inovector\Mixpost\SocialProviders\LinkedIn\LinkedInProvider;
use Inovector\Mixpost\SocialProviders\TikTok\TikTokProvider;
use Inovector\Mixpost\SocialProviders\YouTube\YouTubeProvider;
use Inovector\Mixpost\SocialProviders\Pinterest\PinterestProvider;

class SocialProviderManager extends SocialProviderManagerAbstract
{
    protected array $providers = [];

    public function providers(): array
    {
        if (!empty($this->providers)) {
            return $this->providers;
        }

        return $this->providers = [
            'twitter' => TwitterProvider::class,
            'facebook' => FacebookPageProvider::class,
            'facebook_page' => FacebookPageProvider::class,
            'instagram' => InstagramProvider::class,
            'threads' => ThreadsProvider::class,
            'mastodon' => MastodonProvider::class,
            'linkedin' => LinkedInProvider::class,
            'tiktok' => TikTokProvider::class,
            'youtube' => YouTubeProvider::class,
            'pinterest' => PinterestProvider::class,
        ];
    }

    protected function connectTwitterProvider()
    {
        $config = ServiceManager::get('twitter', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'twitter']);

        return $this->buildConnectionProvider(TwitterProvider::class, $config);
    }

    protected function connectFacebookProvider()
    {
        $config = ServiceManager::get('facebook', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'facebook']);

        return $this->buildConnectionProvider(FacebookPageProvider::class, $config);
    }

    protected function connectFacebookPageProvider()
    {
        $config = ServiceManager::get('facebook', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'facebook_page']);

        return $this->buildConnectionProvider(FacebookPageProvider::class, $config);
    }

    protected function connectInstagramProvider()
    {
        $config = ServiceManager::get('facebook', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'instagram']);

        return $this->buildConnectionProvider(InstagramProvider::class, $config);
    }

    protected function connectThreadsProvider()
    {
        $config = ServiceManager::get('facebook', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'threads']);

        return $this->buildConnectionProvider(ThreadsProvider::class, $config);
    }

    protected function connectMastodonProvider()
    {
        $request = $this->container->request;
        $sessionServerKey = "{$this->config->get('mixpost.cache_prefix')}.mastodon_server";

        if ($request->route() && $request->route()->getName() === 'mixpost.accounts.add') {
            $serverName = $this->container->request->input('server');
            $request->session()->put($sessionServerKey, $serverName); // We keep the server name in the session. We'll need it in the callback
        } else if ($request->route() && $request->route()->getName() === 'mixpost.callbackSocialProvider') {
            $serverName = $request->session()->get($sessionServerKey);
        } else {
            $serverName = $this->values['data']['server']; // Get the server value that have been set on SocialProviderManager::connect($provider, array $values = [])
        }

        $config = ServiceManager::get("mastodon.$serverName", 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'mastodon']);
        $config['values'] = [
            'data' => ['server' => $serverName]
        ];

        return $this->buildConnectionProvider(MastodonProvider::class, $config);
    }

    protected function connectLinkedInProvider()
    {
        $config = ServiceManager::get('linkedin', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'linkedin']);

        return $this->buildConnectionProvider(LinkedInProvider::class, $config);
    }

    protected function connectTikTokProvider()
    {
        $config = ServiceManager::get('tiktok', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'tiktok']);

        return $this->buildConnectionProvider(TikTokProvider::class, $config);
    }

    protected function connectYouTubeProvider()
    {
        $config = ServiceManager::get('youtube', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'youtube']);

        return $this->buildConnectionProvider(YouTubeProvider::class, $config);
    }

    protected function connectPinterestProvider()
    {
        $config = ServiceManager::get('pinterest', 'configuration');

        $config['redirect'] = route('mixpost.callbackSocialProvider', ['provider' => 'pinterest']);

        return $this->buildConnectionProvider(PinterestProvider::class, $config);
    }
}
