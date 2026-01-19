<?php

namespace Inovector\Mixpost\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Inovector\Mixpost\Concerns\Model\BelongsToOrganization;

class Audience extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToOrganization;

    public $table = 'mixpost_audience';

    protected $fillable = [
        'organization_id',
        'account_id',
        'total',
        'date',
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public $timestamps = false;

    public function scopeAccount($query, int $accountId)
    {
        $query->where('account_id', $accountId);
    }
}
