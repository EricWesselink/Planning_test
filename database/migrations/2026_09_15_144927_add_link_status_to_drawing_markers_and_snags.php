<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->string('link_status', 32)->default('ok')->after('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->dropColumn('link_status');
        });
    }
};
