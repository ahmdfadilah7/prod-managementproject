<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hd_projects')) {
            return;
        }

        $map = [
            'pending' => 'planning',
            'backlog' => 'planning',
            'todo' => 'active',
            'in_progress' => 'active',
            'review' => 'active',
            'done' => 'completed',
        ];

        foreach ($map as $from => $to) {
            DB::table('hd_projects')->where('status', $from)->update(['status' => $to]);
        }
    }

    public function down(): void
    {
        // tidak perlu rollback status lama
    }
};
