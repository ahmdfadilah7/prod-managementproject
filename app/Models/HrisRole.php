<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role Spatie di database HRIS (tabel roles, pivot model_has_roles & role_has_permissions).
 */
class HrisRole extends Model
{
  protected $table = 'roles';

  protected $fillable = ['name', 'guard_name'];

  protected $attributes = [
    'guard_name' => 'web',
  ];

  public function getSlugAttribute(): string
  {
    return $this->name;
  }

  public function getIsSystemAttribute(): bool
  {
    return in_array($this->name, config('managementpro.system_roles', []), true);
  }

  public function getColorAttribute(): string
  {
    return config("managementpro.role_colors.{$this->name}", '#6366f1');
  }

  public function getLevelAttribute(): int
  {
    return (int) config("managementpro.role_levels.{$this->name}", 40);
  }

  public function permissions(): BelongsToMany
  {
    return $this->belongsToMany(HrisPermission::class, 'role_has_permissions', 'role_id', 'permission_id');
  }

  public function users(): BelongsToMany
  {
    return $this->belongsToMany(User::class, 'model_has_roles', 'role_id', 'model_id')
      ->wherePivot('model_type', User::class);
  }

  public function isSuperAdmin(): bool
  {
    return $this->name === 'super_admin';
  }
}
