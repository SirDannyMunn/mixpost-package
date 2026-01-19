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
        $parser = new PostContentParser($account, $post);

        $content = $parser->getVersionContent();

        if (empty($content)) {
            $errors = ['This account version has no content.'];
            $response = new SocialProviderResponse(SocialProviderResponseStatus::ERROR, $errors);
            $post->insertErrors($account, $errors, $response);

            return $response;
        }

        $response = $this->connectProvider($account)->publishPost(
            text: $parser->formatBody($content[0]['body']),
            media: $parser->formatMedia($content[0]['media']),
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
