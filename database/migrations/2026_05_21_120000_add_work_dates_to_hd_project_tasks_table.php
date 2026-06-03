<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hd_project_tasks')) {
            return;
        }

        Schema::table('hd_project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('hd_project_tasks', 'end_date')) {
                $table->dropColumn('end_date');
            }
            if (Schema::hasColumn('hd_project_tasks', 'start_date')) {
                $table->dropColumn('start_date');
            }
        });
    }
};
