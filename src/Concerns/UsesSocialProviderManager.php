<?php

namespace Inovector\Mixpost\Concerns;

use Inovector\Mixpost\Contracts\SocialProvider;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Models\Account;

trait UsesSocialProviderManager
{
    public function connectProvider(Account $account): SocialProvider
    {
        if (!empty($account->data['integration'])) {
            return app(\Inovector\Mixpost\Services\Integrations\SharedSocialConnection::class)->connect($account);
        }
        return SocialProviderManager::connect($account->provider, $account->values())
            ->useAccessToken($account->access_token->toArray());
    }
}
