<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HdCategory extends Model
{
  use SoftDeletes;

  protected $table = 'hd_categories';

  protected $fillable = [
    'companies_id',
    'name',
    'users_created',
    'users_updated',
    'users_deleted',
  ];

  public function company(): BelongsTo
  {
    return $this->belongsTo(Company::class, 'companies_id');
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'users_created');
  }

  public function subCategories(): HasMany
  {
    return $this->hasMany(HdSubCategory::class, 'hd_categories_id');
  }

  public function tickets(): HasMany
  {
    return $this->hasMany(HdTicket::class, 'hd_categories_id');
  }

  public function scopeForCompany($query, int $companyId)
  {
    return $query->where('companies_id', $companyId);
  }

  public function scopeAccessibleBy($query, User $user)
  {
    $companyId = $user->companyId();

    if (! $companyId) {
      return $query->whereRaw('1 = 0');
    }

    $query->forCompany($companyId);

    if ($user->hasPermission('projects.view')) {
      return $query;
    }

    return $query->where(function ($q) use ($user) {
      $q->where('users_created', $user->id)
        ->orWhereHas('tickets', function ($t) use ($user) {
          $t->where('reporter_id', $user->id)
            ->orWhere('assigned_to', $user->id);
        });
    });
  }
}
