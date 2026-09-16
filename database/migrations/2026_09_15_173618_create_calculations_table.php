<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('client_name')->nullable();
            $table->string('project_name')->nullable();
            $table->date('dated_on');
            $table->string('status', 32)->default('concept');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->index(['status', 'dated_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculations');
    }
};
