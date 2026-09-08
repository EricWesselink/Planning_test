<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->string('drawing_room_id', 80)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('area_drawing_markers', function (Blueprint $table) {
            $table->dropColumn('drawing_room_id');
        });
    }
};
