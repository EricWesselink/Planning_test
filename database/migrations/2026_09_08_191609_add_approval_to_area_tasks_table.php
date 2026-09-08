<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_tasks', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('completed_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        DB::table('area_tasks')
            ->where('status', 'gereed')
            ->whereNull('approved_at')
            ->update([
                'approved_at' => DB::raw('COALESCE(completed_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('area_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
