<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hd_project_tasks')) {
            return;
        }

        Schema::create('hd_project_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hd_projects_id');
            $table->unsignedBigInteger('companies_id');
            $table->string('task_number', 64);
            $table->string('subject');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('reporter_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('priority', 32)->default('medium');
            $table->string('status', 32)->default('todo');
            $table->unsignedInteger('position')->default(0);
            $table->dateTime('due_at')->nullable();
            $table->unsignedBigInteger('users_created')->nullable();
            $table->unsignedBigInteger('users_updated')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['companies_id', 'task_number'], 'hd_project_tasks_company_number_unique');
            $table->index(['hd_projects_id', 'status']);
            $table->index('assigned_to');

            if (Schema::hasTable('hd_projects')) {
                $table->foreign('hd_projects_id')
                    ->references('id')
                    ->on('hd_projects')
                    ->cascadeOnDelete();
            }

            if (Schema::hasTable('companies')) {
                $table->foreign('companies_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
            }

            if (Schema::hasTable('users')) {
                $table->foreign('reporter_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
                $table->foreign('assigned_to')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
                $table->foreign('users_created')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
                $table->foreign('users_updated')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hd_project_tasks');
    }
};
