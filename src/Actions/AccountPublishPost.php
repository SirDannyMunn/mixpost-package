<?php

namespace Inovector\Mixpost\Actions;

use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PostContentParser;
use Inovector\Mixpost\Support\SocialProviderResponse;

class AccountPublishPost
{
    use UsesSocialProviderManager;

    public function __invoke(Account $account, Post $post): SocialProviderResponse
    {
        $existing = $post->accounts()->where('mixpost_accounts.id', $account->id)->first();
        if ($account->provider === 'tiktok' && $existing?->pivot->provider_post_id) {
            return new SocialProviderResponse(SocialProviderResponseStatus::OK, [
                'id' => $existing->pivot->provider_post_id,
                'data' => json_decode($existing->pivot->data ?? '{}', true) ?: [],
            ]);
        }
        $parser = new PostContentParser($account, $post);

        $content = $parser->getVersionContent();

        if (empty($content)) {
            $errors = ['This account version has no content.'];
            $response = new SocialProviderResponse(SocialProviderResponseStatus::ERROR, $errors);
            $post->insertErrors($account, $errors, $response);

            return $response;
        }

        try {
            $connection = $this->connectProvider($account);
        } catch (\Throwable $error) {
            // Credential retrieval can fail before the provider returns its normal response.
            // Record a failed delivery instead of letting batch completion mark it published.
            $errors = ['Unable to access the connected account. Check Integrations and retry.'];
            $response = new SocialProviderResponse(SocialProviderResponseStatus::ERROR, $errors);
            $post->insertErrors($account, $errors, $response);
            return $response;
        }
        $media = $parser->formatMedia($content[0]['media']);
        if ($media->count() !== count($content[0]['media']) || $media->contains(fn ($item) => !$item || (string) $item->organization_id !== (string) $account->organization_id)) {
            $response = new SocialProviderResponse(SocialProviderResponseStatus::ERROR, ['Media is missing or unavailable in this workspace.']);
            $post->insertErrors($account, $response->context(), $response);
            return $response;
        }
        $response = $connection->publishPost(
            text: $parser->formatBody($content[0]['body']),
            media: $media,
            params: $parser->getVersionOptions()
        );

        if ($response->hasError()) {
            $errorInfo = $response->context();
            if (empty($errorInfo) && $response->errorMessage()) {
                $errorInfo = ['message' => $response->errorMessage()];
            }
            $post->insertErrors($account, $errorInfo, $response);

            return $response;
        }

        $post->insertProviderData($account, $response);

        return $response;
    }
}
