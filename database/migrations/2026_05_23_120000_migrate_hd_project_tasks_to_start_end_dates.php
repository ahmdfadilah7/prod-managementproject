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
            if (! Schema::hasColumn('hd_project_tasks', 'start_date')) {
                $table->dateTime('start_date')->nullable()->after('position');
            }
            if (! Schema::hasColumn('hd_project_tasks', 'end_date')) {
                $table->dateTime('end_date')->nullable()->after('start_date');
            }
        });

        DB::table('hd_project_tasks')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $start = $row->work_date ?? $row->due_at ?? null;
                $end = $row->due_at ?? $row->work_date ?? null;

                if ($start && $end && $end < $start) {
                    [$start, $end] = [$end, $start];
                }

                DB::table('hd_project_tasks')->where('id', $row->id)->update([
                    'start_date' => $start,
                    'end_date' => $end,
                ]);
            }
        });

        Schema::table('hd_project_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('hd_project_tasks', 'work_date')) {
                $table->dropColumn('work_date');
            }
            if (Schema::hasColumn('hd_project_tasks', 'due_at')) {
                $table->dropColumn('due_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hd_project_tasks')) {
            return;
        }

        Schema::table('hd_project_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('hd_project_tasks', 'due_at')) {
                $table->dateTime('due_at')->nullable()->after('position');
            }
            if (! Schema::hasColumn('hd_project_tasks', 'work_date')) {
                $table->dateTime('work_date')->nullable()->after('due_at');
            }
        });

        DB::table('hd_project_tasks')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('hd_project_tasks')->where('id', $row->id)->update([
                    'work_date' => $row->start_date,
                    'due_at' => $row->end_date ?? $row->start_date,
                ]);
            }
        });

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
