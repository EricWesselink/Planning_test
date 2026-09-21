<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->decimal('approved_hours', 8, 2)->nullable()->after('hours');
        });

        DB::table('time_entries')
            ->where('status', 'goedgekeurd')
            ->whereNull('approved_hours')
            ->update(['approved_hours' => DB::raw('hours')]);
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('approved_hours');
        });
    }
};
