<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_calculation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_hash', 64);
            $table->string('source_filename');
            $table->string('km', 16)->nullable();
            $table->string('group_code', 64)->nullable();
            $table->string('mu', 16)->nullable();
            $table->string('article_number', 64)->nullable();
            $table->string('production_description', 500)->nullable();
            $table->string('article_description', 500)->nullable();
            $table->string('unit', 32)->nullable();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->decimal('hours', 10, 4)->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('labor_cost', 12, 2)->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->boolean('is_labor')->default(false);
            $table->string('work_match_key', 120)->nullable();
            $table->string('work_match_label', 255)->nullable();
            $table->string('match_status', 32)->default('review');
            $table->string('naca_code', 64)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'source_hash', 'row_number'], 'calc_lines_source_row_unique');
            $table->index(['project_id', 'is_labor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_calculation_lines');
    }
};
