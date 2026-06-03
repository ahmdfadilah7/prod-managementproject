<?php

namespace App\Services;

use App\Models\HrisPermission;
use App\Models\HrisRole;
use App\Support\Hris\HrisPermissionGrouper;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HrisRoleService
{
  public function list(?string $search = null): Collection
  {
    return HrisRole::query()
      ->with('permissions')
      ->withCount('users')
      ->when($search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
      ->orderByRaw("FIELD(name, 'super_admin', 'hr', 'ga', 'employee') DESC")
      ->orderBy('name')
      ->get();
  }

  public function find(int $id): HrisRole
  {
    return HrisRole::with('permissions')->withCount('users')->findOrFail($id);
  }

  public function create(array $data): HrisRole
  {
    $name = Str::snake(Str::lower(trim($data['name'])));

    if (HrisRole::where('name', $name)->exists()) {
      abort(422, 'Nama role sudah digunakan.');
    }

    $role = HrisRole::create([
      'name' => $name,
      'guard_name' => 'web',
    ]);

    $role->permissions()->sync($data['permission_ids']);

    return $role->load('permissions')->loadCount('users');
  }

  public function update(HrisRole $role, array $data): HrisRole
  {
    if ($role->is_system && isset($data['name']) && $data['name'] !== $role->name) {
      abort(422, 'Nama role sistem tidak dapat diubah.');
    }

    if (isset($data['name']) && ! $role->is_system) {
      $name = Str::snake(Str::lower(trim($data['name'])));
      if (HrisRole::where('name', $name)->where('id', '!=', $role->id)->exists()) {
        abort(422, 'Nama role sudah digunakan.');
      }
      $role->update(['name' => $name]);
    }

    if (isset($data['permission_ids']) && ! $role->isSuperAdmin()) {
      $role->permissions()->sync($data['permission_ids']);
    }

    return $role->fresh(['permissions'])->loadCount('users');
  }

  public function delete(HrisRole $role): void
  {
    if ($role->is_system) {
      abort(422, 'Role sistem tidak dapat dihapus.');
    }

    if ($role->users()->exists()) {
      abort(422, 'Role masih digunakan oleh pengguna.');
    }

    $role->permissions()->detach();
    $role->delete();
  }

  public function duplicate(HrisRole $role): HrisRole
  {
    $base = $role->name.'_copy';
    $name = $base;
    $i = 1;
    while (HrisRole::where('name', $name)->exists()) {
      $name = $base.'_'.$i;
      $i++;
    }

    $copy = HrisRole::create([
      'name' => $name,
      'guard_name' => 'web',
    ]);

    $copy->permissions()->sync($role->permissions()->pluck('id'));

    return $copy->load('permissions')->loadCount('users');
  }

  public function permissionsList(): array
  {
    $permissions = HrisPermission::query()
      ->where('guard_name', 'web')
      ->orderBy('name')
      ->get();

    $grouped = HrisPermissionGrouper::groupCollection($permissions);

    return [
      'data' => $permissions->map(fn ($p) => $this->permissionToArray($p)),
      'grouped' => $grouped->map(fn ($g) => [
        'group' => $g['group'],
        'label' => $g['label'],
        'permissions' => $g['permissions']->map(fn ($p) => $this->permissionToArray($p))->values(),
      ])->values(),
    ];
  }

  public function roleToArray(HrisRole $role): array
  {
    return [
      'id' => $role->id,
      'name' => ucwords(str_replace('_', ' ', $role->name)),
      'slug' => $role->name,
      'description' => null,
      'color' => $role->color,
      'level' => $role->level,
      'is_system' => $role->is_system,
      'guard_name' => $role->guard_name,
      'users_count' => $role->users_count ?? $role->users()->count(),
      'permissions' => $role->relationLoaded('permissions')
        ? $role->permissions->map(fn ($p) => $this->permissionToArray($p))->values()->all()
        : [],
      'permission_ids' => $role->relationLoaded('permissions')
        ? $role->permissions->pluck('id')->all()
        : [],
      'created_at' => $role->created_at?->toIso8601String(),
      'updated_at' => $role->updated_at?->toIso8601String(),
    ];
  }

  public function permissionToArray(HrisPermission $permission): array
  {
    return [
      'id' => $permission->id,
      'name' => HrisPermissionGrouper::humanName($permission->name),
      'slug' => $permission->name,
      'group' => HrisPermissionGrouper::groupFromName($permission->name),
      'description' => null,
    ];
  }
}
