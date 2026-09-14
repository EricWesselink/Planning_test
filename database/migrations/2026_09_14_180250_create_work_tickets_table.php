<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->string('kind', 32);
            $table->foreignId('worker_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('billing_method', 32)->nullable();
            $table->decimal('hourly_rate', 12, 2)->nullable();
            $table->decimal('fixed_price', 12, 2)->nullable();
            $table->decimal('worked_hours', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            $table->unique('number');
            $table->index(['worker_id', 'start_date']);
            $table->index('worker_assignment_id');
        });

        Schema::create('work_ticket_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_floor_id')->constrained()->cascadeOnDelete();
            $table->boolean('entire_floor')->default(false);
            $table->timestamps();
            $table->unique(['work_ticket_id', 'project_floor_id']);
        });

        Schema::create('work_ticket_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_area_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['work_ticket_id', 'project_area_id']);
        });

        Schema::create('work_ticket_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 16);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('work_ticket_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_document_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['work_ticket_id', 'project_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_ticket_documents');
        Schema::dropIfExists('work_ticket_lines');
        Schema::dropIfExists('work_ticket_areas');
        Schema::dropIfExists('work_ticket_floors');
        Schema::dropIfExists('work_tickets');
    }
};
