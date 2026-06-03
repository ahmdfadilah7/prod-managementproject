<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\HrisRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
  public function index(Request $request): JsonResponse
  {
    $query = User::with(User::authEagerLoads());

    if (config('managementpro.hris_mode')) {
      $query
        ->with('employee')
        ->when($request->string('search')->trim()->toString(), function ($q, $search) {
          $q->where(function ($inner) use ($search) {
            $inner->where('email_kantor', 'like', "%{$search}%")
              ->orWhere('email_pribadi', 'like', "%{$search}%")
              ->orWhere('nik', 'like', "%{$search}%")
              ->orWhereHas('employee', fn ($e) => $e->where('nama_lengkap', 'like', "%{$search}%"));
          });
        })
        ->when($request->filled('role_id'), fn ($q) => $q->whereHas(
          'roles',
          fn ($r) => $r->where('roles.id', $request->integer('role_id'))
        ))
        ->when($request->has('is_active'), fn ($q) => $q->where(
          'status',
          filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN) ? 'active' : 'inactive'
        ));
    } else {
      $query
        ->when($request->string('search')->trim()->toString(), function ($q, $search) {
          $q->where(function ($inner) use ($search) {
            $inner->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('job_title', 'like', "%{$search}%");
          });
        })
        ->when($request->filled('role_id'), fn ($q) => $q->whereHas(
          'roles',
          fn ($r) => $r->where('roles.id', $request->integer('role_id'))
        ))
        ->when($request->has('is_active'), fn ($q) => $q->where(
          'is_active',
          filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN)
        ));
    }

    return response()->json([
      'data' => UserResource::collection($query->latest('id')->paginate($request->integer('per_page', 15))),
    ]);
  }

  public function store(Request $request): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      abort(403, 'Buat pengguna baru dilakukan melalui modul HRIS utama.');
    }

    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'email' => ['required', 'email', 'max:255', 'unique:users,email'],
      'password' => ['required', Password::min(8)],
      'job_title' => ['nullable', 'string', 'max:255'],
      'phone' => ['nullable', 'string', 'max:20'],
      'is_active' => ['boolean'],
      'role_ids' => ['required', 'array', 'min:1'],
      'role_ids.*' => ['exists:roles,id'],
      'primary_role_id' => ['required', 'exists:roles,id'],
    ]);

    $user = User::create([
      'name' => $validated['name'],
      'email' => $validated['email'],
      'password' => Hash::make($validated['password']),
      'job_title' => $validated['job_title'] ?? null,
      'phone' => $validated['phone'] ?? null,
      'is_active' => $validated['is_active'] ?? true,
    ]);

    $user->syncRoles($validated['role_ids'], (int) $validated['primary_role_id']);

    return response()->json([
      'data' => new UserResource($user->load(User::authEagerLoads())),
      'message' => 'Pengguna berhasil dibuat.',
    ], 201);
  }

  public function show(User $user): JsonResponse
  {
    return response()->json([
      'data' => new UserResource($user->load(User::authEagerLoads())),
    ]);
  }

  public function update(Request $request, User $user): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      $validated = $request->validate([
        'role_ids' => ['sometimes', 'array', 'min:1'],
        'role_ids.*' => ['exists:roles,id'],
        'is_active' => ['sometimes', 'boolean'],
      ]);

      if (isset($validated['is_active'])) {
        $user->update([
          'status' => $validated['is_active'] ? 'active' : 'inactive',
        ]);
      }

      if (isset($validated['role_ids'])) {
        $user->syncRoles($validated['role_ids']);
      }

      return response()->json([
        'data' => new UserResource($user->fresh(User::authEagerLoads())),
        'message' => 'Pengguna berhasil diperbarui.',
      ]);
    }

    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:255'],
      'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
      'password' => ['nullable', Password::min(8)],
      'job_title' => ['nullable', 'string', 'max:255'],
      'phone' => ['nullable', 'string', 'max:20'],
      'is_active' => ['boolean'],
      'role_ids' => ['sometimes', 'array', 'min:1'],
      'role_ids.*' => ['exists:roles,id'],
      'primary_role_id' => ['required_with:role_ids', 'exists:roles,id'],
    ]);

    $data = collect($validated)->except(['password', 'role_ids', 'primary_role_id'])->toArray();

    if (! empty($validated['password'])) {
      $data['password'] = Hash::make($validated['password']);
    }

    $user->update($data);

    if (isset($validated['role_ids'])) {
      $user->syncRoles($validated['role_ids'], (int) $validated['primary_role_id']);
    }

    return response()->json([
      'data' => new UserResource($user->fresh(User::authEagerLoads())),
      'message' => 'Pengguna berhasil diperbarui.',
    ]);
  }

  public function destroy(Request $request, User $user): JsonResponse
  {
    if ($user->id === $request->user()->id) {
      return response()->json(['message' => 'Tidak dapat menghapus akun sendiri.'], 422);
    }

    if ($user->hasRole('super_admin')) {
      $count = config('managementpro.hris_mode')
        ? DB::table('model_has_roles')
          ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
          ->where('roles.name', 'super_admin')
          ->where('model_has_roles.model_type', User::class)
          ->count()
        : User::whereHas('roles', fn ($q) => $q->where('slug', 'super_admin'))->count();

      if ($count <= 1) {
        return response()->json(['message' => 'Harus ada minimal satu Super Admin.'], 422);
      }
    }

    if (config('managementpro.hris_mode')) {
      abort(403, 'Hapus pengguna dilakukan melalui modul HRIS utama.');
    }

    $user->delete();

    return response()->json(['message' => 'Pengguna berhasil dihapus.']);
  }

  public function toggleStatus(Request $request, User $user): JsonResponse
  {
    if ($user->id === $request->user()->id) {
      return response()->json(['message' => 'Tidak dapat menonaktifkan akun sendiri.'], 422);
    }

    if (config('managementpro.hris_mode')) {
      $user->update([
        'status' => $user->status === 'active' ? 'inactive' : 'active',
      ]);
    } else {
      $user->update(['is_active' => ! $user->is_active]);
    }

    return response()->json([
      'data' => new UserResource($user->fresh(User::authEagerLoads())),
      'message' => $user->is_active ? 'Pengguna diaktifkan.' : 'Pengguna dinonaktifkan.',
    ]);
  }

  public function stats(): JsonResponse
  {
    if (config('managementpro.hris_mode')) {
      return response()->json([
        'total' => User::count(),
        'active' => User::where('status', 'active')->count(),
        'inactive' => User::where('status', '!=', 'active')->count(),
        'by_role' => HrisRole::withCount('users')->get()->map(fn ($r) => [
          'role' => ucwords(str_replace('_', ' ', $r->name)),
          'slug' => $r->name,
          'color' => $r->color,
          'count' => $r->users_count,
        ]),
      ]);
    }

    return response()->json([
      'total' => User::count(),
      'active' => User::where('is_active', true)->count(),
      'inactive' => User::where('is_active', false)->count(),
      'by_role' => Role::withCount('users')->get()->map(fn ($r) => [
        'role' => $r->name,
        'slug' => $r->slug,
        'color' => $r->color,
        'count' => $r->users_count,
      ]),
    ]);
  }
}
