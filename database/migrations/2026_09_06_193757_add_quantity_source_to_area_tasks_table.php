<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_tasks', function (Blueprint $table) {
            $table->string('quantity_source', 64)->nullable()->after('ordered_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('area_tasks', function (Blueprint $table) {
            $table->dropColumn('quantity_source');
        });
    }
};
