<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('approved_at');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });

        DB::table('snag_items')->whereNotNull('approved_at')->update([
            'closed_at' => DB::raw('approved_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
    }
};
