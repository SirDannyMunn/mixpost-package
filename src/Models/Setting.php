<?php

namespace Inovector\Mixpost\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Inovector\Mixpost\Concerns\Model\BelongsToOrganization;
use Inovector\Mixpost\Facades\Settings as SettingsFacade;

class Setting extends Model
{
    use HasUuids;
    use BelongsToOrganization;

    public $table = 'mixpost_settings';

    protected $fillable = [
        'organization_id',
        'name',
        'payload'
    ];

    protected $casts = [
        'payload' => 'array'
    ];

    public $timestamps = false;

    protected static function booted()
    {
        static::saved(function ($setting) {
            SettingsFacade::put($setting->name, $setting->payload);
        });

        static::deleted(function ($setting) {
            SettingsFacade::forget($setting->name);
        });
    }
}
