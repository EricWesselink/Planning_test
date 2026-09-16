<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calculation_drawing_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('room_number')->nullable();
            $table->string('room_name')->nullable();
            $table->string('product_code')->nullable();
            $table->string('product')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit', 16);
            $table->string('source', 32);
            $table->text('note')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['calculation_id', 'sort_order']);
            $table->index(['calculation_id', 'product_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_lines');
    }
};
