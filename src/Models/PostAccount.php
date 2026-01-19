<?php

namespace Inovector\Mixpost\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PostAccount extends Pivot
{
    use HasUuids;

    protected $table = 'mixpost_post_accounts';

    public $incrementing = false;

    protected $keyType = 'string';
}
