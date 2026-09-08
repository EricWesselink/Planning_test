<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->boolean('friday_off')->default(false)->after('active');
        });

        Schema::create('worker_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('kind', 16);
            $table->timestamps();
            $table->index(['worker_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_availabilities');

        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('friday_off');
        });
    }
};
