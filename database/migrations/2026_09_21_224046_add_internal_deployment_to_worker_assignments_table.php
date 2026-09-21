<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('worker_assignments', 'kind')) {
                $table->string('kind', 32)->default('project');
            }
            if (! Schema::hasColumn('worker_assignments', 'business_unit')) {
                $table->string('business_unit', 64)->nullable();
            }
            if (! Schema::hasColumn('worker_assignments', 'description')) {
                $table->string('description')->nullable();
            }
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('worker_assignments')->where('kind', 'internal')->delete();

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->dropColumn(['kind', 'business_unit', 'description']);
        });
    }
};
