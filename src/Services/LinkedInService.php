<?php

namespace Inovector\Mixpost\Services;

use Inovector\Mixpost\Abstracts\Service;
use Inovector\Mixpost\Enums\ServiceGroup;

class LinkedInService extends Service
{
    public static array $exposedFormAttributes = [];

    public static function name(): string
    {
        return 'linkedin';
    }

    public static function group(): ServiceGroup
    {
        return ServiceGroup::SOCIAL;
    }

    static function form(): array
    {
        return [
            'client_id' => '',
            'client_secret' => ''
        ];
    }

    public static function formRules(): array
    {
        return [
            'client_id' => ['required'],
            'client_secret' => ['required']
        ];
    }

    public static function formMessages(): array
    {
        return [
            'client_id' => 'The Client ID is required.',
            'client_secret' => 'The Client Secret is required.'
        ];
    }
}
