<?php

namespace Inovector\Mixpost\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Inovector\Mixpost\Concerns\Model\BelongsToOrganization;

class ImportedPost extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToOrganization;

    public $table = 'mixpost_imported_posts';

    protected $fillable = [
        'organization_id',
        'account_id',
        'provider_post_id',
        'content',
        'metrics',
        'created_at'
    ];

    protected $casts = [
        'content' => 'array',
        'metrics' => 'array',
        'created_at' => 'date' // TODO: change type of this column from `date` to `datetime`
    ];

    public $timestamps = false;
}
