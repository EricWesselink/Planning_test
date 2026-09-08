<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->json('polygon')->nullable()->after('y');
        });
    }

    public function down(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->dropColumn('polygon');
        });
    }
};
