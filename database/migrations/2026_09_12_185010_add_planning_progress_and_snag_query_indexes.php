<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->index('archived_at');
        });

        Schema::table('work_progress_entries', function (Blueprint $table) {
            $table->index(['project_id', 'date']);
        });

        Schema::table('snag_items', function (Blueprint $table) {
            $table->index(['project_id', 'status']);
        });

        Schema::table('project_areas', function (Blueprint $table) {
            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('project_areas', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'sort_order']);
        });

        Schema::table('snag_items', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status']);
        });

        Schema::table('work_progress_entries', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'date']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
        });
    }
};
