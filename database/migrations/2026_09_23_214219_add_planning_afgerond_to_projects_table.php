<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('planning_afgerond')->default(false)->after('archived_at');
            $table->timestamp('afgerond_at')->nullable()->after('planning_afgerond');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['planning_afgerond', 'afgerond_at']);
        });
    }
};
