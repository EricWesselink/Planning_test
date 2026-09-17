<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('meter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('ordered_at')->nullable();
            $table->date('installation_at')->nullable();
            $table->timestamps();
        });

        Schema::create('measurement_form_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('measurement_form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('room', 120)->nullable();
            $table->string('product', 120)->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('type', 80)->nullable();
            $table->string('color_number', 40)->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit', 8)->nullable();
            $table->string('underlay', 80)->nullable();
            $table->string('skirting', 80)->nullable();
            $table->string('steps', 80)->nullable();
            $table->string('profile', 80)->nullable();
            $table->boolean('available_on_site')->default(false);
            $table->timestamps();
            $table->index(['measurement_form_id', 'sort_order']);
        });

        Schema::table('work_tickets', function (Blueprint $table) {
            $table->boolean('include_measurement_form')->default(true)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('work_tickets', function (Blueprint $table) {
            $table->dropColumn('include_measurement_form');
        });
        Schema::dropIfExists('measurement_form_rows');
        Schema::dropIfExists('measurement_forms');
    }
};
