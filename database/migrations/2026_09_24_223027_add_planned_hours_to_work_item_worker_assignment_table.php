<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_item_worker_assignment', function (Blueprint $table) {
            $table->decimal('planned_hours', 4, 1)->nullable()->after('work_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_item_worker_assignment', function (Blueprint $table) {
            $table->dropColumn('planned_hours');
        });
    }
};
