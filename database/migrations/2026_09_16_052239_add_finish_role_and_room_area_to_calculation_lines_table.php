<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->string('finish_role', 16)->nullable()->after('unit');
            $table->decimal('room_area', 12, 3)->nullable()->after('finish_role');
        });
    }

    public function down(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->dropColumn(['finish_role', 'room_area']);
        });
    }
};
