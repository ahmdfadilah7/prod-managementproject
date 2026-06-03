<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hd_projects')) {
            return;
        }

        Schema::create('hd_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('hd_sub_categories_id');
            $table->string('project_number', 64);
            $table->string('subject');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('reporter_id');
            $table->string('priority', 32)->default('medium');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('users_created')->nullable();
            $table->unsignedBigInteger('users_updated')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['companies_id', 'project_number'], 'hd_projects_company_number_unique');
            $table->index(['hd_sub_categories_id', 'status']);
            $table->index('reporter_id');

            if (Schema::hasTable('companies')) {
                $table->foreign('companies_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
            }

            if (Schema::hasTable('hd_sub_categories')) {
                $table->foreign('hd_sub_categories_id')
                    ->references('id')
                    ->on('hd_sub_categories')
                    ->restrictOnDelete();
            }

            if (Schema::hasTable('users')) {
                $table->foreign('reporter_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
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

        if (Schema::hasTable('hd_project_user')) {
            return;
        }

        Schema::create('hd_project_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hd_project_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['hd_project_id', 'user_id']);
            $table->index('user_id');

            if (Schema::hasTable('hd_projects')) {
                $table->foreign('hd_project_id')
                    ->references('id')
                    ->on('hd_projects')
                    ->cascadeOnDelete();
            }

            if (Schema::hasTable('users')) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hd_project_user');
        Schema::dropIfExists('hd_projects');
    }
};
