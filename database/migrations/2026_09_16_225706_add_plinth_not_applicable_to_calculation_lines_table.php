<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->boolean('plinth_not_applicable')->default(false)->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->dropColumn('plinth_not_applicable');
        });
    }
};
