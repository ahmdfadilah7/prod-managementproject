<?php

return [
    'groups' => [
        'dashboard' => 'Dashboard',
        'users' => 'User Management',
        'roles' => 'Role Management',
        'projects' => 'Projects',
        'tasks' => 'Tasks',
    ],

    'permissions' => [
        ['slug' => 'dashboard.view', 'name' => 'View Dashboard', 'group' => 'dashboard'],

        ['slug' => 'users.view', 'name' => 'View Users', 'group' => 'users'],
        ['slug' => 'users.create', 'name' => 'Create Users', 'group' => 'users'],
        ['slug' => 'users.update', 'name' => 'Update Users', 'group' => 'users'],
        ['slug' => 'users.delete', 'name' => 'Delete Users', 'group' => 'users'],
        ['slug' => 'users.toggle_status', 'name' => 'Activate/Deactivate Users', 'group' => 'users'],

        ['slug' => 'roles.view', 'name' => 'View Roles', 'group' => 'roles'],
        ['slug' => 'roles.create', 'name' => 'Create Roles', 'group' => 'roles'],
        ['slug' => 'roles.update', 'name' => 'Update Roles', 'group' => 'roles'],
        ['slug' => 'roles.delete', 'name' => 'Delete Roles', 'group' => 'roles'],

        ['slug' => 'projects.view', 'name' => 'View Projects', 'group' => 'projects'],
        ['slug' => 'projects.create', 'name' => 'Create Projects', 'group' => 'projects'],
        ['slug' => 'projects.update', 'name' => 'Update Projects', 'group' => 'projects'],
        ['slug' => 'projects.delete', 'name' => 'Delete Projects', 'group' => 'projects'],
        ['slug' => 'projects.manage_members', 'name' => 'Manage Project Members', 'group' => 'projects'],

        ['slug' => 'tasks.view', 'name' => 'View Tasks', 'group' => 'tasks'],
        ['slug' => 'tasks.create', 'name' => 'Create Tasks', 'group' => 'tasks'],
        ['slug' => 'tasks.update', 'name' => 'Update Tasks', 'group' => 'tasks'],
        ['slug' => 'tasks.delete', 'name' => 'Delete Tasks', 'group' => 'tasks'],
    ],

    'role_templates' => [
        'super_admin' => '*',
        'admin' => [
            'dashboard.view',
            'users.view', 'users.create', 'users.update', 'users.delete', 'users.toggle_status',
            'roles.view', 'roles.create', 'roles.update',
            'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.manage_members',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete',
        ],
        'project_manager' => [
            'dashboard.view',
            'users.view',
            'projects.view', 'projects.create', 'projects.update', 'projects.manage_members',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete',
        ],
        'member' => [
            'dashboard.view',
            'projects.view',
            'tasks.view', 'tasks.create', 'tasks.update',
        ],
        'viewer' => [
            'dashboard.view',
            'projects.view',
            'tasks.view',
        ],
    ],
];
