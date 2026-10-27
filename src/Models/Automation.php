<?php

namespace Inovector\Mixpost\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Automation extends Model
{
    use HasUuids;

    protected $table = 'mixpost_automations';
    protected $guarded = ['id'];
    protected $casts = [
        'config' => 'array', 'enabled' => 'boolean', 'next_run_at' => 'datetime',
        'expires_at' => 'datetime', 'last_run_at' => 'datetime',
        'revision' => 'integer', 'run_count' => 'integer', 'max_runs' => 'integer',
    ];
}
