<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_number')->nullable()->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_number')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->string('status', 32)->default('gepland');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('project_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('project_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_floor_id')->nullable()->constrained('project_floors')->nullOnDelete();
            $table->string('area_number')->nullable();
            $table->string('name');
            $table->decimal('square_meters', 12, 2)->default(0);
            $table->string('status', 32)->default('niet_gestart');
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 16)->default('m2');
            $table->decimal('ordered_quantity', 12, 2)->default(0);
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('status', 32)->default('gepland');
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('employment_type', 32)->default('eigen');
            $table->string('company')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('specialty')->nullable();
            $table->decimal('default_hours_per_day', 5, 2)->default(8);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });

        Schema::create('worker_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedTinyInteger('people_count')->default(1);
            $table->decimal('hours_per_day', 5, 2)->default(8);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['worker_id', 'start_date', 'end_date']);
            $table->index(['project_id', 'start_date', 'end_date']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->string('assignment_type', 32);
            $table->decimal('assigned_quantity', 12, 2)->nullable();
            $table->string('unit', 16)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 32)->default('gepland');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('work_progress_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('completed_quantity', 12, 2)->default(0);
            $table->string('unit', 16);
            $table->decimal('worked_hours', 10, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['work_item_id', 'date']);
            $table->index(['worker_id', 'date']);
        });

        Schema::create('project_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 32)->default('overig');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('parse_status', 32)->default('none');
            $table->json('parsed_json')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note');
            $table->boolean('is_blocker')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_notes');
        Schema::dropIfExists('project_documents');
        Schema::dropIfExists('work_progress_entries');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('worker_assignments');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('workers');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('project_areas');
        Schema::dropIfExists('project_floors');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('customers');
    }
};
