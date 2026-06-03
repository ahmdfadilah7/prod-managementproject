<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hd_project_task_attachments')) {
            return;
        }

        Schema::table('hd_project_task_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('hd_project_task_attachments', 'description')) {
                $table->text('description')->nullable()->after('original_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hd_project_task_attachments')) {
            return;
        }

        Schema::table('hd_project_task_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('hd_project_task_attachments', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
