<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hd_project_tasks')) {
            return;
        }

        Schema::table('hd_project_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('hd_project_tasks', 'work_date')) {
                $table->dateTime('work_date')->nullable()->after('due_at');
            }
        });

        if (Schema::hasColumn('hd_project_tasks', 'start_date') || Schema::hasColumn('hd_project_tasks', 'end_date')) {
            DB::table('hd_project_tasks')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $work = $row->end_date ?? $row->start_date ?? null;
                    if ($work && Schema::hasColumn('hd_project_tasks', 'work_date')) {
                        DB::table('hd_project_tasks')->where('id', $row->id)->update(['work_date' => $work]);
                    }
                }
            });
        }

        Schema::table('hd_project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('hd_project_tasks', 'end_date')) {
                $table->dropColumn('end_date');
            }
            if (Schema::hasColumn('hd_project_tasks', 'start_date')) {
                $table->dropColumn('start_date');
            }
        });

        if (Schema::hasTable('hd_project_task_attachments')) {
            return;
        }

        Schema::create('hd_project_task_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hd_project_tasks_id');
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('users_created')->nullable();
            $table->timestamps();

            $table->index('hd_project_tasks_id');

            if (Schema::hasTable('hd_project_tasks')) {
                $table->foreign('hd_project_tasks_id')
                    ->references('id')
                    ->on('hd_project_tasks')
                    ->cascadeOnDelete();
            }

            if (Schema::hasTable('users')) {
                $table->foreign('users_created')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hd_project_task_attachments');

        if (! Schema::hasTable('hd_project_tasks')) {
            return;
        }

        Schema::table('hd_project_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('hd_project_tasks', 'start_date')) {
                $table->dateTime('start_date')->nullable()->after('due_at');
            }
            if (! Schema::hasColumn('hd_project_tasks', 'end_date')) {
                $table->dateTime('end_date')->nullable()->after('start_date');
            }
            if (Schema::hasColumn('hd_project_tasks', 'work_date')) {
                $table->dropColumn('work_date');
            }
        });
    }
};
