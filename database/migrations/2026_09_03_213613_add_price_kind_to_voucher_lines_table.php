<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->string('price_kind', 16)->default('unit')->after('price_source');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropColumn('price_kind');
        });
    }
};
