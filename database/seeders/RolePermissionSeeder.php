<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('permissions.permissions') as $perm) {
            Permission::updateOrCreate(
                ['slug' => $perm['slug']],
                $perm
            );
        }

        $allPermissionIds = Permission::pluck('id');

        $roles = [
            [
                'name' => 'Super Admin',
                'slug' => 'super_admin',
                'description' => 'Akses penuh ke seluruh sistem',
                'color' => '#dc2626',
                'level' => 100,
                'is_system' => true,
                'permissions' => '*',
            ],
            [
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'Kelola pengguna, proyek, dan sebagian besar fitur',
                'color' => '#6366f1',
                'level' => 90,
                'is_system' => true,
                'permissions' => config('permissions.role_templates.admin'),
            ],
            [
                'name' => 'Project Manager',
                'slug' => 'project_manager',
                'description' => 'Kelola proyek dan tim proyek',
                'color' => '#0891b2',
                'level' => 70,
                'is_system' => true,
                'permissions' => config('permissions.role_templates.project_manager'),
            ],
            [
                'name' => 'Member',
                'slug' => 'member',
                'description' => 'Kontributor proyek standar',
                'color' => '#22c55e',
                'level' => 50,
                'is_system' => true,
                'permissions' => config('permissions.role_templates.member'),
            ],
            [
                'name' => 'Viewer',
                'slug' => 'viewer',
                'description' => 'Hanya melihat proyek dan task',
                'color' => '#94a3b8',
                'level' => 10,
                'is_system' => true,
                'permissions' => config('permissions.role_templates.viewer'),
            ],
        ];

        foreach ($roles as $roleData) {
            $permissionSlugs = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::updateOrCreate(
                ['slug' => $roleData['slug']],
                $roleData
            );

            if ($permissionSlugs === '*') {
                $role->permissions()->sync($allPermissionIds);
            } else {
                $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id');
                $role->permissions()->sync($ids);
            }
        }
    }
}
