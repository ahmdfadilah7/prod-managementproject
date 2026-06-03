<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HrisPermission extends Model
{
  protected $table = 'permissions';

  protected $fillable = ['name', 'guard_name'];

  public function roles(): BelongsToMany
  {
    return $this->belongsToMany(HrisRole::class, 'role_has_permissions', 'permission_id', 'role_id');
  }

  public function getSlugAttribute(): string
  {
    return $this->name;
  }
}
