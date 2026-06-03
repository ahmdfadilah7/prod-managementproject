<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\HrisRole;
use App\Models\Permission;
use App\Models\Role;
use App\Services\HrisRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
  public function __construct(
    protected HrisRoleService $hrisRoles
  ) {}

  public function index(Request $request): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $roles = $this->hrisRoles->list($request->string('search')->trim()->toString() ?: null);

      return response()->json([
        'data' => $roles->map(fn (HrisRole $r) => $this->hrisRoles->roleToArray($r)),
        'meta' => ['hris_rbac' => true],
      ]);
    }

    $roles = Role::with('permissions')
      ->withCount('users')
      ->when($request->string('search')->trim()->toString(), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
      ->orderByDesc('level')
      ->get();

    return response()->json(['data' => RoleResource::collection($roles)]);
  }

  public function store(Request $request): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'permission_ids' => ['required', 'array', 'min:1'],
        'permission_ids.*' => ['exists:permissions,id'],
      ]);

      $role = $this->hrisRoles->create($validated);

      return response()->json([
        'data' => $this->hrisRoles->roleToArray($role),
        'message' => 'Role berhasil dibuat.',
      ], 201);
    }

    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'color' => ['nullable', 'string', 'max:7'],
      'level' => ['nullable', 'integer', 'min:1', 'max:99'],
      'permission_ids' => ['required', 'array'],
      'permission_ids.*' => ['exists:permissions,id'],
    ]);

    $role = Role::create([
      'name' => $validated['name'],
      'slug' => Str::slug($validated['name']).'-'.Str::random(4),
      'description' => $validated['description'] ?? null,
      'color' => $validated['color'] ?? '#6366f1',
      'level' => $validated['level'] ?? 40,
      'is_system' => false,
    ]);

    $role->permissions()->sync($validated['permission_ids']);

    return response()->json([
      'data' => new RoleResource($role->load('permissions')),
      'message' => 'Role berhasil dibuat.',
    ], 201);
  }

  public function show(int $role): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $model = $this->hrisRoles->find($role);

      return response()->json([
        'data' => $this->hrisRoles->roleToArray($model),
      ]);
    }

    $model = Role::findOrFail($role);

    return response()->json([
      'data' => new RoleResource($model->load('permissions')->loadCount('users')),
    ]);
  }

  public function update(Request $request, int $role): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $model = $this->hrisRoles->find($role);

      if ($model->isSuperAdmin() && ! $request->user()->hasHrisRole('super_admin')) {
        abort(403);
      }

      $validated = $request->validate([
        'name' => ['sometimes', 'string', 'max:255'],
        'permission_ids' => ['sometimes', 'array', 'min:1'],
        'permission_ids.*' => ['exists:permissions,id'],
      ]);

      $model = $this->hrisRoles->update($model, $validated);

      return response()->json([
        'data' => $this->hrisRoles->roleToArray($model),
        'message' => 'Role berhasil diperbarui.',
      ]);
    }

    $model = Role::findOrFail($role);

    if ($model->slug === 'super_admin' && ! $request->user()->hasRole('super_admin')) {
      abort(403);
    }

    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'color' => ['nullable', 'string', 'max:7'],
      'level' => ['nullable', 'integer', 'min:1', 'max:99'],
      'permission_ids' => ['sometimes', 'array'],
      'permission_ids.*' => ['exists:permissions,id'],
    ]);

    if ($model->is_system && isset($validated['name'])) {
      unset($validated['name']);
    }

    $model->update(collect($validated)->except('permission_ids')->toArray());

    if (isset($validated['permission_ids']) && $model->slug !== 'super_admin') {
      $model->permissions()->sync($validated['permission_ids']);
    }

    return response()->json([
      'data' => new RoleResource($model->fresh(['permissions'])->loadCount('users')),
      'message' => 'Role berhasil diperbarui.',
    ]);
  }

  public function destroy(int $role): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $this->hrisRoles->delete($this->hrisRoles->find($role));

      return response()->json(['message' => 'Role berhasil dihapus.']);
    }

    $model = Role::findOrFail($role);

    if ($model->is_system) {
      return response()->json(['message' => 'Role sistem tidak dapat dihapus.'], 422);
    }

    if ($model->users()->exists()) {
      return response()->json(['message' => 'Role masih digunakan oleh pengguna.'], 422);
    }

    $model->delete();

    return response()->json(['message' => 'Role berhasil dihapus.']);
  }

  public function permissions(): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $payload = $this->hrisRoles->permissionsList();

      return response()->json([
        ...$payload,
        'meta' => ['hris_rbac' => true],
      ]);
    }

    $permissions = Permission::orderBy('group')->orderBy('name')->get();
    $grouped = $permissions->groupBy('group')->map(fn ($items, $group) => [
      'group' => $group,
      'label' => config("permissions.groups.{$group}", ucfirst($group)),
      'permissions' => PermissionResource::collection($items),
    ])->values();

    return response()->json([
      'data' => $permissions,
      'grouped' => $grouped,
    ]);
  }

  public function duplicate(int $role): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $copy = $this->hrisRoles->duplicate($this->hrisRoles->find($role));

      return response()->json([
        'data' => $this->hrisRoles->roleToArray($copy),
        'message' => 'Role berhasil diduplikasi.',
      ], 201);
    }

    $model = Role::findOrFail($role);

    $copy = Role::create([
      'name' => $model->name.' (Copy)',
      'slug' => Str::slug($model->name).'-copy-'.Str::random(4),
      'description' => $model->description,
      'color' => $model->color,
      'level' => max(1, $model->level - 5),
      'is_system' => false,
    ]);

    $copy->permissions()->sync($model->permissions()->pluck('permissions.id'));

    return response()->json([
      'data' => new RoleResource($copy->load('permissions')),
      'message' => 'Role berhasil diduplikasi.',
    ], 201);
  }
}
