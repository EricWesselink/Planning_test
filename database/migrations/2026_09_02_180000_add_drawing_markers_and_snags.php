<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_drawing_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_area_id')->constrained('project_areas')->cascadeOnDelete();
            $table->foreignId('project_document_id')->constrained('project_documents')->cascadeOnDelete();
            $table->unsignedInteger('page')->default(1);
            $table->decimal('x', 8, 6);
            $table->decimal('y', 8, 6);
            $table->decimal('confidence', 5, 4)->default(1);
            $table->string('source', 16)->default('auto');
            $table->timestamps();
            $table->unique(['project_area_id', 'project_document_id'], 'area_drawing_unique');
        });

        Schema::create('snag_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_area_id')->nullable()->constrained('project_areas')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('project_documents')->nullOnDelete();
            $table->unsignedInteger('drawing_page')->default(1);
            $table->decimal('x', 8, 6)->nullable();
            $table->decimal('y', 8, 6)->nullable();
            $table->unsignedInteger('number');
            $table->text('description')->nullable();
            $table->foreignId('assigned_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->string('priority', 16)->default('normal');
            $table->date('due_date')->nullable();
            $table->string('status', 32)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });

        Schema::create('snag_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snag_item_id')->constrained('snag_items')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->string('photo_type', 16)->default('issue');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('snag_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snag_item_id')->constrained('snag_items')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->foreignId('worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snag_history');
        Schema::dropIfExists('snag_photos');
        Schema::dropIfExists('snag_items');
        Schema::dropIfExists('area_drawing_markers');
    }
};
