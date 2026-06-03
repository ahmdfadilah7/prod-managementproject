<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $admin = User::create([
            'name' => 'Ahmad Developer',
            'email' => 'admin@managementpro.test',
            'password' => Hash::make('password'),
            'job_title' => 'Lead Developer',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin', true);

        $sarah = User::create([
            'name' => 'Sarah Wijaya',
            'email' => 'sarah@managementpro.test',
            'password' => Hash::make('password'),
            'job_title' => 'UI/UX Designer',
            'is_active' => true,
        ]);
        $sarah->assignRole('project_manager', true);

        $budi = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@managementpro.test',
            'password' => Hash::make('password'),
            'job_title' => 'Backend Engineer',
            'is_active' => true,
        ]);
        $budi->assignRole('member', true);

        $project = Project::create([
            'owner_id' => $admin->id,
            'name' => 'ManagementPro Platform',
            'slug' => 'managementpro-platform',
            'description' => 'Platform project management internal perusahaan dengan fitur Kanban, milestone, dan analytics.',
            'color' => '#6366f1',
            'status' => 'active',
            'priority' => 'high',
            'progress' => 45,
            'start_date' => now()->subDays(14),
            'due_date' => now()->addDays(30),
        ]);

        $project->members()->attach([
            $admin->id => ['role' => 'owner'],
            $sarah->id => ['role' => 'admin'],
            $budi->id => ['role' => 'member'],
        ]);

        $labels = collect([
            ['name' => 'Bug', 'color' => '#ef4444'],
            ['name' => 'Feature', 'color' => '#22c55e'],
            ['name' => 'Design', 'color' => '#a855f7'],
            ['name' => 'Urgent', 'color' => '#f97316'],
        ])->map(fn ($l) => $project->labels()->create($l));

        $tasks = [
            ['title' => 'Setup arsitektur Vue + Laravel', 'status' => 'done', 'priority' => 'high', 'assignee_id' => $admin->id, 'position' => 1],
            ['title' => 'Desain sistem UI dashboard', 'status' => 'done', 'priority' => 'medium', 'assignee_id' => $sarah->id, 'position' => 2],
            ['title' => 'Implementasi API authentication', 'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $budi->id, 'position' => 1],
            ['title' => 'Kanban board drag & drop', 'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $admin->id, 'position' => 2],
            ['title' => 'Integrasi notifikasi real-time', 'status' => 'review', 'priority' => 'medium', 'assignee_id' => $budi->id, 'position' => 1],
            ['title' => 'Unit test API endpoints', 'status' => 'todo', 'priority' => 'medium', 'assignee_id' => $budi->id, 'position' => 1],
            ['title' => 'Dokumentasi deployment', 'status' => 'todo', 'priority' => 'low', 'assignee_id' => $admin->id, 'position' => 2],
            ['title' => 'Research competitor analysis', 'status' => 'backlog', 'priority' => 'low', 'assignee_id' => $sarah->id, 'position' => 1],
        ];

        foreach ($tasks as $i => $data) {
            $task = $project->tasks()->create([
                ...$data,
                'created_by' => $admin->id,
                'description' => 'Task demo untuk pengujian fitur ManagementPro.',
                'due_date' => now()->addDays(rand(3, 20)),
                'story_points' => rand(1, 8),
                'completed_at' => $data['status'] === 'done' ? now() : null,
            ]);
            $task->labels()->attach($labels->random(rand(1, 2))->pluck('id'));
        }

        $project2 = Project::create([
            'owner_id' => $sarah->id,
            'name' => 'Mobile App Redesign',
            'slug' => 'mobile-app-redesign',
            'description' => 'Redesign aplikasi mobile dengan fokus UX modern.',
            'color' => '#ec4899',
            'status' => 'planning',
            'priority' => 'medium',
            'progress' => 10,
            'due_date' => now()->addDays(60),
        ]);
        $project2->members()->attach($sarah->id, ['role' => 'owner']);
        $project2->members()->attach($admin->id, ['role' => 'member']);

        ActivityLogger::log($project, $admin, 'project.created', 'membuat proyek demo ManagementPro');
        ActivityLogger::log($project, $sarah, 'task.updated', 'memperbarui desain UI dashboard');

        AppNotification::create([
            'user_id' => $admin->id,
            'type' => 'task.assigned',
            'title' => 'Task baru ditugaskan',
            'message' => 'Anda ditugaskan pada "Kanban board drag & drop"',
            'data' => ['project_id' => $project->id],
        ]);
    }
}
