<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->decimal('begrote_uren', 10, 2)->nullable()->after('ordered_quantity');
            $table->decimal('begrote_hoeveelheid', 12, 2)->nullable()->after('begrote_uren');
            $table->decimal('uurtarief', 10, 2)->nullable()->after('begrote_hoeveelheid');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['begrote_uren', 'begrote_hoeveelheid', 'uurtarief']);
        });
    }
};
