<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->decimal('label_x', 8, 6)->nullable()->after('label_text');
            $table->decimal('label_y', 8, 6)->nullable()->after('label_x');
        });
    }

    public function down(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->dropColumn(['label_x', 'label_y']);
        });
    }
};
