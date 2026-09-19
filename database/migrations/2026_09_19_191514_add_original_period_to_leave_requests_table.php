<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->date('original_starts_on')->nullable()->after('ends_on');
            $table->date('original_ends_on')->nullable()->after('original_starts_on');
            $table->foreignId('period_adjusted_by')->nullable()->after('original_ends_on')->constrained('users')->nullOnDelete();
            $table->timestamp('period_adjusted_at')->nullable()->after('period_adjusted_by');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('period_adjusted_by');
            $table->dropColumn(['original_starts_on', 'original_ends_on', 'period_adjusted_at']);
        });
    }
};
