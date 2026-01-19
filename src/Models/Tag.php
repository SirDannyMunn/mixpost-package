<?php

namespace Inovector\Mixpost\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Inovector\Mixpost\Concerns\Model\BelongsToOrganization;
use Inovector\Mixpost\Concerns\Model\HasUuid;

class Tag extends Model
{
    use HasFactory;
    use HasUuids;
    use HasUuid;
    use BelongsToOrganization;

    public $table = 'mixpost_tags';

    protected $fillable = [
        'organization_id',
        'name',
        'hex_color'
    ];
}
