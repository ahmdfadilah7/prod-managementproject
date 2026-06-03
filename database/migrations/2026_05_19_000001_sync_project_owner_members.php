<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Project::query()->whereNotNull('owner_id')->each(function (Project $project) {
            $project->syncOwnerAsMember();
        });
    }

    public function down(): void
    {
        // No rollback — membership sync is safe to keep
    }
};
