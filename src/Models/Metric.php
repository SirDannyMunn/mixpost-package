<?php

namespace Inovector\Mixpost\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Inovector\Mixpost\Concerns\Model\BelongsToOrganization;

class Metric extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToOrganization;

    public $table = 'mixpost_metrics';

    protected $fillable = [
        'organization_id',
        'account_id',
        'data',
        'date',
    ];

    protected $casts = [
        'data' => 'array',
        'date' => 'date'
    ];

    public $timestamps = false;

    public function scopeAccount($query, int $accountId)
    {
        $query->where('account_id', $accountId);
    }
}
