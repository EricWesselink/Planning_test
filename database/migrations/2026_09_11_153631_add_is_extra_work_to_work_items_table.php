<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->boolean('is_extra_work')->default(false)->after('notes');
            $table->index(['project_id', 'is_extra_work']);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'is_extra_work']);
            $table->dropColumn('is_extra_work');
        });
    }
};
