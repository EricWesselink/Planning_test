<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->string('specialty', 64);
            $table->string('unit', 16);
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();
            $table->unique(['worker_id', 'specialty', 'unit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_rates');
    }
};
