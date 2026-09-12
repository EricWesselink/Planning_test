<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->timestamp('public_token_expires_at')->nullable()->after('public_token');
            $table->timestamp('public_token_revoked_at')->nullable()->after('public_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->dropColumn(['public_token_expires_at', 'public_token_revoked_at']);
        });
    }
};
