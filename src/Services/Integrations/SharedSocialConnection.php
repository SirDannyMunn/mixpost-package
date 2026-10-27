<?php

namespace Inovector\Mixpost\Services\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Contracts\SocialProvider;
use Inovector\Mixpost\Models\Account;
use LaundryOS\Integrations\Modules\Nango\{NangoClient, WorkspaceConnection};
use LaundryOS\Integrations\Services\Mail\EmailProvider;

class SharedSocialConnection
{
    public function __construct(private readonly NangoClient $nango, private readonly WorkspaceConnection $connections) {}

    public static function provider(string $name): ?string
    {
        return match ($name) {
            'linkedin', 'linkedin-community-management' => 'linkedin',
            'twitter', 'twitter-v2', 'x' => 'twitter',
            'facebook', 'facebook-pages' => 'facebook_page',
            'instagram', 'instagram-business' => 'instagram',
            'youtube' => 'youtube', 'tiktok', 'tiktok-sandbox', 'tiktok-personal' => 'tiktok', 'pinterest' => 'pinterest',
            'threads' => 'threads',
            default => null,
        };
    }

    public function catalog(string $org): array
    {
        $integrations = [];
        $disabled = \LaundryOS\Integrations\Models\IntegrationCatalogSetting::where('enabled', false)->pluck('integration_key')->all();
        foreach (EmailProvider::rows($this->nango->listIntegrations(), ['data', 'integrations']) as $item) {
            $provider = self::provider($item['provider'] ?? '');
            $key = EmailProvider::first($item, ['unique_key', 'provider_config_key', 'id']);
            if ($provider && $key && !in_array('nango:'.$key, $disabled, true)) $integrations[$key] = ['key' => $key, 'provider' => $provider, 'name' => $item['display_name'] ?? $provider];
        }
        $connections = [];
        $accounts = Account::where('organization_id', $org)->get(['id', 'organization_id', 'name', 'username', 'media', 'data']);
        foreach (EmailProvider::rows($this->nango->listConnections(['tags' => ['organization_id' => $org]]), ['connections', 'data']) as $item) {
            $key = $item['provider_config_key'] ?? '';
            if (isset($integrations[$key]) && EmailProvider::organization($item) === $org && EmailProvider::healthy($item)) {
                $account = $accounts->first(fn ($account) => ($account->data['integration']['key'] ?? null) === $key && ($account->data['integration']['connection_id'] ?? null) === $item['connection_id']);
                // end_user identifies the workspace user, not the connected social profile.
                $name = $account?->name ?: EmailProvider::first($item, ['metadata.account_display_name', 'metadata.display_name']);
                $connections[] = ['key' => $key, 'id' => $item['connection_id'], 'provider' => $integrations[$key]['provider'],
                    'name' => $name ?: null, 'name_is_placeholder' => !$name,
                    'username' => $account?->username ?: '', 'image' => $account?->image()];
            }
        }
        return ['integrations' => array_values($integrations), 'connections' => $connections];
    }

    private function resolve(string $org, string $key, string $id): array
    {
        $setting = \LaundryOS\Integrations\Models\IntegrationCatalogSetting::where('integration_key', 'nango:'.$key)->first();
        if ($setting && !$setting->enabled) throw ValidationException::withMessages(['connection' => 'This integration is disabled.']);
        $record = $this->connections->get($org, $key, $id);
        $provider = self::provider($record['provider'] ?? '');
        if (!$provider) {
            foreach (EmailProvider::rows($this->nango->listIntegrations(), ['data', 'integrations']) as $item) {
                if (EmailProvider::first($item, ['unique_key', 'provider_config_key', 'id']) === $key) $provider = self::provider($item['provider'] ?? '');
            }
        }
        $token = $record['credentials']['access_token'] ?? null;
        if (!$provider || !is_string($token) || $token === '') throw ValidationException::withMessages(['connection' => 'Select a supported OAuth 2 social connection. X OAuth 1 connections must use the existing account setup.']);
        return [$provider, ['access_token' => $token]];
    }

    private function adapter(string $provider, array $token, array $values = []): SocialProvider
    {
        $class = \Inovector\Mixpost\Facades\SocialProviderManager::providers()[$provider];
        $adapter = (new $class(request(), '', '', '', $values))->useAccessToken($token);
        if ($provider === 'instagram') $adapter->useInstagramLogin();
        return $adapter;
    }

    public function targets(string $org, string $key, string $id): array
    {
        [$provider, $token] = $this->resolve($org, $key, $id);
        $adapter = $this->adapter($provider, $token, ['provider_id' => 'me']);
        $response = $provider === 'facebook_page' ? $adapter->getEntities() : $adapter->getAccount();
        if ($response->hasError()) throw ValidationException::withMessages(['connection' => 'Could not read this social profile. Check its permissions in Integrations.']);
        $targets = $provider === 'facebook_page' ? $response->context() : [$response->context()];
        $linked = Account::where('organization_id', $org)->where('provider', $provider)->where('authorized', true)->pluck('provider_id')->map(fn ($value) => (string) $value)->all();
        return array_map(fn ($target) => ['id' => (string) $target['id'], 'name' => $target['name'], 'username' => $target['username'] ?? '',
            'image' => $this->profileImage($target), 'linked' => in_array((string) $target['id'], $linked, true)], $targets);
    }

    private function profileImage(array $target): ?string
    {
        $image = $target['image'] ?? $target['avatar_url'] ?? null;
        return is_string($image) && filter_var($image, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($image, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) ? $image : null;
    }

    public function link(string $org, string $actor, string $key, string $id, string $target): Account
    {
        [$provider] = $this->resolve($org, $key, $id);
        $profile = collect($this->targets($org, $key, $id))->firstWhere('id', $target);
        if (!$profile) throw ValidationException::withMessages(['target' => 'Select a profile belonging to this connection.']);
        return DB::transaction(function () use ($org, $actor, $key, $id, $provider, $profile) {
            $account = Account::firstOrNew(['organization_id' => $org, 'provider' => $provider, 'provider_id' => $profile['id']]);
            $account->fill(['name' => $profile['name'], 'username' => $profile['username'], 'connected_by' => $actor, 'connected_at' => now(), 'authorized' => true, 'access_token' => [],
                'data' => [...($account->data ?? []), 'integration' => ['key' => $key, 'connection_id' => $id]]]);
            $account->save();
            return $account;
        });
    }

    public function connect(Account $account): SocialProvider
    {
        $ref = $account->data['integration'];
        [$provider, $token] = $this->resolve((string) $account->organization_id, $ref['key'], $ref['connection_id']);
        abort_unless($provider === $account->provider, 422, 'Connection provider changed. Reconnect this social account.');
        if ($provider === 'facebook_page') {
            $response = $this->adapter($provider, $token)->getEntities(true);
            if ($response->hasError()) throw ValidationException::withMessages(['connection' => 'Unable to access Facebook Pages. Reconnect in Integrations.']);
            $page = collect($response->context())->first(fn ($page) => (string) $page['id'] === (string) $account->provider_id);
            if (!$page || empty($page['access_token']['access_token'])) throw ValidationException::withMessages(['connection' => 'This Facebook Page is no longer accessible.']);
            $token['page_access_token'] = $page['access_token']['access_token'];
        }
        $adapter = $this->adapter($provider, $token, $account->values());
        if ($provider !== 'facebook_page') {
            $profile = $adapter->getAccount();
            if ($profile->hasError() || (string) ($profile->context()['id'] ?? '') !== (string) $account->provider_id) {
                throw ValidationException::withMessages(['connection' => 'The connected profile changed or is unavailable. Relink it from the scheduler.']);
            }
        }
        return $adapter;
    }
}
