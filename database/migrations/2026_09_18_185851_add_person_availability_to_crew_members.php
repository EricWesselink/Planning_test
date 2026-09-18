<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_members', function (Blueprint $table) {
            $table->boolean('friday_off')->default(false)->after('specialty');
            $table->boolean('unavailable')->default(false)->after('friday_off');
        });

        Schema::table('worker_availabilities', function (Blueprint $table) {
            $table->foreignId('crew_member_id')->nullable()->after('worker_id')->constrained()->cascadeOnDelete();
        });

        $workers = DB::table('workers')->select('id', 'friday_off', 'unavailable')->get();
        foreach ($workers as $worker) {
            DB::table('crew_members')->where('worker_id', $worker->id)->update([
                'friday_off' => (bool) $worker->friday_off,
                'unavailable' => (bool) $worker->unavailable,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('worker_availabilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crew_member_id');
        });

        Schema::table('crew_members', function (Blueprint $table) {
            $table->dropColumn(['friday_off', 'unavailable']);
        });
    }
};
