<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('worker_assignments', 'people_count')) {
                $table->unsignedTinyInteger('people_count')->default(1)->after('end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('worker_assignments', 'people_count')) {
                $table->dropColumn('people_count');
            }
        });
    }
};
