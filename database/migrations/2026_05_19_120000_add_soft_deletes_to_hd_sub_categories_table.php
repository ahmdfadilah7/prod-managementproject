<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hd_sub_categories')) {
            return;
        }

        if (! Schema::hasColumn('hd_sub_categories', 'deleted_at')) {
            Schema::table('hd_sub_categories', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hd_sub_categories') && Schema::hasColumn('hd_sub_categories', 'deleted_at')) {
            Schema::table('hd_sub_categories', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
