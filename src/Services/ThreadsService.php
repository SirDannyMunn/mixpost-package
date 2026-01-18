<?php

namespace Inovector\Mixpost\Services;

use Illuminate\Validation\Rule;
use Inovector\Mixpost\Abstracts\Service;
use Inovector\Mixpost\Enums\ServiceGroup;

class ThreadsService extends Service
{
    public static function group(): ServiceGroup
    {
        return ServiceGroup::SOCIAL;
    }

    public static function versions(): array
    {
        return ['v1.0'];
    }

    static function form(): array
    {
        return [
            'client_id' => '',
            'client_secret' => '',
            'api_version' => current(self::versions())
        ];
    }

    public static function formRules(): array
    {
        return [
            "client_id" => ['required'],
            "client_secret" => ['required'],
            "api_version" => ['required', Rule::in(self::versions())],
        ];
    }

    public static function formMessages(): array
    {
        return [
            'client_id' => 'The App ID is required.',
            'client_secret' => 'The APP Secret is required.',
            'api_version' => 'The API Version is required.',
        ];
    }
}
