<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->decimal('labor_unit_price', 10, 2)->nullable()->after('uurtarief');
        });

        DB::table('work_items')
            ->whereIn('unit', ['m2', 'm1'])
            ->where('begrote_uren', '>', 0)
            ->where('begrote_hoeveelheid', '>', 0)
            ->whereNotNull('uurtarief')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $item): void {
                DB::table('work_items')->where('id', $item->id)->update([
                    'labor_unit_price' => round(((float) $item->begrote_uren * (float) $item->uurtarief) / (float) $item->begrote_hoeveelheid, 2),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('labor_unit_price');
        });
    }
};
