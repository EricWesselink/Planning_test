<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_column_memories', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_header');
            $table->string('role', 32);
            $table->unsignedInteger('confirmations')->default(1);
            $table->timestamps();

            $table->unique('normalized_header');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_column_memories');
    }
};
