<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_area_id')->constrained('project_areas')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('ordered_quantity', 12, 2)->default(0);
            $table->string('unit', 16)->default('m2');
            $table->string('status', 32)->default('niet_gestart');
            $table->foreignId('completed_by')->nullable()->constrained('workers')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_area_id', 'work_item_id']);
        });

        Schema::table('work_progress_entries', function (Blueprint $table) {
            $table->foreignId('project_area_id')
                ->nullable()
                ->after('work_item_id')
                ->constrained('project_areas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_progress_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_area_id');
        });

        Schema::dropIfExists('area_tasks');
    }
};
