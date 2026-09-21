<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('business_unit');
        });

        DB::table('worker_assignments')
            ->where('business_unit', 'nico_dekvloeren')
            ->update(['business_unit' => 'vloeren']);

        DB::table('worker_assignments')
            ->whereIn('business_unit', ['screens_zonwering', 'overig'])
            ->update(['business_unit' => null]);
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->dropColumn('contact_name');
        });
    }
};
