<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->decimal('width', 8, 6)->nullable()->after('y');
            $table->decimal('height', 8, 6)->nullable()->after('width');
            $table->string('label_text', 160)->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->dropColumn(['width', 'height', 'label_text']);
        });
    }
};
