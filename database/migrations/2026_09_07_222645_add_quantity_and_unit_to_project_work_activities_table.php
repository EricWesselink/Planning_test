<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_work_activities', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->nullable()->after('notes');
            $table->string('unit', 16)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('project_work_activities', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit']);
        });
    }
};
