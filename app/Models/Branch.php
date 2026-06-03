<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'companies_id',
        'name',
        'email',
        'nomor_telepon',
        'nomor_fax',
        'nomor_hp',
        'address',
        'timezone',
        'latitude',
        'longitude',
        'radius',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'companies_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'branches_id');
    }
}
