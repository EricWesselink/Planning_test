<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_tickets', function (Blueprint $table) {
            $table->json('day_hours')->nullable()->after('worked_hours');
        });
    }

    public function down(): void
    {
        Schema::table('work_tickets', function (Blueprint $table) {
            $table->dropColumn('day_hours');
        });
    }
};
