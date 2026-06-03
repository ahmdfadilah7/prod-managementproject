<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AssignDemoRolesSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'admin@managementpro.test' => 'super_admin',
            'sarah@managementpro.test' => 'project_manager',
            'budi@managementpro.test' => 'member',
        ];

        foreach ($map as $email => $role) {
            $user = User::where('email', $email)->first();
            if ($user && ! $user->roles()->exists()) {
                $user->assignRole($role, true);
            }
        }
    }
}
