<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('worker_assignments', 'include_weekends')) {
                $table->boolean('include_weekends')->default(false)->after('end_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('worker_assignments', 'include_weekends')) {
                $table->dropColumn('include_weekends');
            }
        });
    }
};
