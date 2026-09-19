<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['leave_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_messages');
    }
};
