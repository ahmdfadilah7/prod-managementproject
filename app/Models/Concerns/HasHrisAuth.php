<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

trait HasHrisAuth
{
  public static function usesHrisSchema(): bool
  {
    return (bool) config('managementpro.hris_mode');
  }

  public function companyId(): ?int
  {
    if (! static::usesHrisSchema()) {
      return null;
    }

    return $this->employee?->companies_id
      ? (int) $this->employee->companies_id
      : (int) config('managementpro.default_company_id');
  }

  public function getHrisPermissions(): array
  {
    if (! static::usesHrisSchema()) {
      return [];
    }

    $roleIds = DB::table('model_has_roles')
      ->where('model_type', self::class)
      ->where('model_id', $this->id)
      ->pluck('role_id');

    if ($roleIds->isEmpty()) {
      return [];
    }

    return DB::table('role_has_permissions')
      ->whereIn('role_id', $roleIds)
      ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
      ->pluck('permissions.name')
      ->unique()
      ->values()
      ->all();
  }

  public function hasHrisRole(string|array $names): bool
  {
    $names = (array) $names;

    return DB::table('model_has_roles')
      ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
      ->where('model_has_roles.model_type', self::class)
      ->where('model_has_roles.model_id', $this->id)
      ->whereIn('roles.name', $names)
      ->exists();
  }

  public function getHrisRolesForApi(): array
  {
    $rows = DB::table('model_has_roles')
      ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
      ->where('model_has_roles.model_type', self::class)
      ->where('model_has_roles.model_id', $this->id)
      ->select('roles.id', 'roles.name')
      ->get();

    return $rows->map(fn ($r) => [
      'id' => $r->id,
      'name' => ucfirst(str_replace('_', ' ', $r->name)),
      'slug' => $r->name,
      'color' => '#6366f1',
    ])->all();
  }

  public function mapHrisPermissionsToSlugs(): array
  {
    $allSlugs = array_keys(config('managementpro.permission_map', []));

    if (! config('managementpro.permission_map_enabled', true)) {
      return $allSlugs;
    }

    if ($this->hasHrisRole('super_admin')) {
      return $allSlugs;
    }

    $hrisPerms = $this->getHrisPermissions();
    $slugs = [];

    foreach (config('managementpro.permission_map', []) as $slug => $hrisName) {
      if (in_array($hrisName, $hrisPerms, true)) {
        $slugs[] = $slug;
      }
    }

    return $slugs;
  }
}
