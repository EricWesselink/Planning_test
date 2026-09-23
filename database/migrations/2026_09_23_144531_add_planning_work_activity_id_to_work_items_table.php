<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('planning_work_activity_id')
                ->nullable()
                ->after('work_activity_id')
                ->constrained('work_activities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planning_work_activity_id');
        });
    }
};
