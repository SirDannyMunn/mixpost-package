<?php

namespace Inovector\Mixpost\Services;

use Inovector\Mixpost\Abstracts\Service;
use Inovector\Mixpost\Enums\ServiceGroup;

class TikTokService extends Service
{
    public static array $exposedFormAttributes = [];

    public static function name(): string
    {
        return 'tiktok';
    }

    public static function group(): ServiceGroup
    {
        return ServiceGroup::SOCIAL;
    }

    static function form(): array
    {
        return [
            'client_key' => '',
            'client_secret' => ''
        ];
    }

    public static function formRules(): array
    {
        return [
            'client_key' => ['required'],
            'client_secret' => ['required']
        ];
    }

    public static function formMessages(): array
    {
        return [
            'client_key' => 'The Client Key is required.',
            'client_secret' => 'The Client Secret is required.'
        ];
    }
}
