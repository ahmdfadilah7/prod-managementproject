<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HdSubCategory extends Model
{
    protected $table = 'hd_sub_categories';

    protected $fillable = [
        'hd_categories_id',
        'name',
        'sla_minutes',
        'users_created',
        'users_updated',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(HdCategory::class, 'hd_categories_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(HdTicket::class, 'hd_sub_categories_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(HdProject::class, 'hd_sub_categories_id');
    }
}
