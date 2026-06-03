<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hd_projects')) {
            return;
        }

        Schema::table('hd_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('hd_projects', 'start_date')) {
                $table->dateTime('start_date')->nullable()->after('status');
            }
            if (! Schema::hasColumn('hd_projects', 'end_date')) {
                $table->dateTime('end_date')->nullable()->after('start_date');
            }
            if (! Schema::hasColumn('hd_projects', 'position')) {
                $table->unsignedInteger('position')->default(0)->after('end_date');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hd_projects')) {
            return;
        }

        Schema::table('hd_projects', function (Blueprint $table) {
            if (Schema::hasColumn('hd_projects', 'position')) {
                $table->dropColumn('position');
            }
            if (Schema::hasColumn('hd_projects', 'end_date')) {
                $table->dropColumn('end_date');
            }
            if (Schema::hasColumn('hd_projects', 'start_date')) {
                $table->dropColumn('start_date');
            }
        });
    }
};
