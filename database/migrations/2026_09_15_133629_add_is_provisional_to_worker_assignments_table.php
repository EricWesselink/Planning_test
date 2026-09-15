<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('worker_assignments', 'is_provisional')) {
                $table->boolean('is_provisional')->default(false)->after('planned_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('worker_assignments', 'is_provisional')) {
                $table->dropColumn('is_provisional');
            }
        });
    }
};
