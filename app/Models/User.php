<?php

namespace App\Models;

use App\Models\Concerns\HasHrisAuth;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasHrisAuth, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'email_kantor',
        'email_pribadi',
        'password',
        'avatar',
        'job_title',
        'is_active',
        'status',
        'phone',
        'last_login_at',
        'employees_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employees_id');
    }

    public function getNameAttribute(): string
    {
        if (static::usesHrisSchema()) {
            return $this->employee?->nama_lengkap
                ?? $this->attributes['nik']
                ?? $this->attributes['name']
                ?? 'User';
        }

        return $this->attributes['name'] ?? '';
    }

    public function getEmailAttribute(): ?string
    {
        if (static::usesHrisSchema()) {
            return $this->attributes['email_kantor']
                ?? $this->attributes['email_pribadi']
                ?? null;
        }

        return $this->attributes['email'] ?? null;
    }

    public function getJobTitleAttribute(): ?string
    {
        if (static::usesHrisSchema()) {
            return $this->employee?->position?->name;
        }

        return $this->attributes['job_title'] ?? null;
    }

    public function getIsActiveAttribute(): bool
    {
        if (static::usesHrisSchema()) {
            return ($this->attributes['status'] ?? 'inactive') === 'active';
        }

        return (bool) ($this->attributes['is_active'] ?? true);
    }

    public function roles(): BelongsToMany
    {
        if (static::usesHrisSchema()) {
            return $this->belongsToMany(HrisRole::class, 'model_has_roles', 'model_id', 'role_id')
                ->wherePivot('model_type', self::class);
        }

        return $this->belongsToMany(Role::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /** Relasi yang aman untuk response auth (hindari role_user di mode HRIS). */
    public static function authEagerLoads(): array
    {
        return static::usesHrisSchema()
            ? ['employee.position', 'employee.branch']
            : ['roles'];
    }

    /**
     * Zona waktu untuk menampilkan tanggal/waktu (dari cabang karyawan).
     */
    public function displayTimezone(): string
    {
        if (static::usesHrisSchema()) {
            $tz = $this->employee?->branch?->timezone;

            if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
                return $tz;
            }
        }

        return (string) config('managementpro.fallback_display_timezone', 'UTC');
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('role')->withTimestamps();
    }

    public function reportedHdProjects(): HasMany
    {
        return $this->hasMany(HdProject::class, 'reporter_id');
    }

    public function assignedHdProjects(): BelongsToMany
    {
        return $this->belongsToMany(HdProject::class, 'hd_project_user', 'user_id', 'hd_project_id')
            ->withTimestamps();
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function primaryRole(): Role|HrisRole|null
    {
        if (static::usesHrisSchema()) {
            return $this->roles->first();
        }

        return $this->roles->first(fn ($r) => $r->pivot->is_primary)
            ?? $this->roles->sortByDesc('level')->first();
    }

    public function getAllPermissions(): Collection
    {
        if (static::usesHrisSchema()) {
            return collect();
        }

        if ($this->hasRole('super_admin')) {
            return Permission::all();
        }

        return $this->roles
            ->loadMissing('permissions')
            ->flatMap(fn (Role $role) => $role->permissions)
            ->unique('id')
            ->values();
    }

    public function getPermissionSlugs(): array
    {
        if (static::usesHrisSchema()) {
            return $this->mapHrisPermissionsToSlugs();
        }

        return $this->getAllPermissions()->pluck('slug')->all();
    }

    public function hasRole(string|array $slugs): bool
    {
        $slugs = (array) $slugs;

        if (static::usesHrisSchema()) {
            return $this->hasHrisRole($slugs);
        }

        return $this->roles->contains(fn (Role $role) => in_array($role->slug, $slugs, true));
    }

    public function hasPermission(string $permission): bool
    {
        if (static::usesHrisSchema()) {
            if ($this->hasHrisRole('super_admin')) {
                return true;
            }

            $mapped = config("managementpro.permission_map.{$permission}");

            if (! $mapped) {
                return true;
            }

            return in_array($mapped, $this->getHrisPermissions(), true);
        }

        if ($this->hasRole('super_admin')) {
            return true;
        }

        return in_array($permission, $this->getPermissionSlugs(), true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function syncRoles(array $roleIds, ?int $primaryRoleId = null): void
    {
        if (static::usesHrisSchema()) {
            $this->roles()->sync(
                collect($roleIds)->mapWithKeys(fn ($id) => [
                    $id => ['model_type' => self::class],
                ])->all()
            );

            return;
        }

        $sync = [];
        foreach ($roleIds as $roleId) {
            $sync[$roleId] = ['is_primary' => $roleId === $primaryRoleId];
        }
        $this->roles()->sync($sync);
    }

    public function assignRole(string|int $role, bool $primary = false): void
    {
        if (static::usesHrisSchema()) {
            $roleId = is_numeric($role)
                ? (int) $role
                : HrisRole::where('name', $role)->value('id');

            if (! $roleId) {
                return;
            }

            $this->roles()->syncWithoutDetaching([
                $roleId => ['model_type' => self::class],
            ]);

            return;
        }

        $roleId = is_numeric($role) ? (int) $role : Role::where('slug', $role)->value('id');
        if (! $roleId) {
            return;
        }

        if ($primary) {
            foreach ($this->roles as $existing) {
                $this->roles()->updateExistingPivot($existing->id, ['is_primary' => false]);
            }
        }

        $this->roles()->syncWithoutDetaching([
            $roleId => ['is_primary' => $primary],
        ]);
    }
}
