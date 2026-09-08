<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_area_id')->nullable()->constrained('project_areas')->nullOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('specialty_key', 64)->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 16);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->string('price_source', 32)->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_lines');
    }
};
