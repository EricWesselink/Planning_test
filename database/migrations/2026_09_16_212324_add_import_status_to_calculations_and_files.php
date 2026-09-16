<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculations', function (Blueprint $table) {
            $table->string('import_status', 32)->default('ready')->after('status');
            $table->index('import_status');
        });

        Schema::table('calculation_drawings', function (Blueprint $table) {
            $table->string('import_status', 32)->default('ready')->after('warnings');
            $table->text('import_error')->nullable()->after('import_status');
            $table->index(['calculation_id', 'import_status']);
        });

        Schema::table('calculation_workbooks', function (Blueprint $table) {
            $table->string('import_status', 32)->default('ready')->after('status');
            $table->text('import_error')->nullable()->after('import_status');
            $table->index(['calculation_id', 'import_status']);
        });
    }

    public function down(): void
    {
        Schema::table('calculations', function (Blueprint $table) {
            $table->dropIndex(['import_status']);
            $table->dropColumn('import_status');
        });

        Schema::table('calculation_drawings', function (Blueprint $table) {
            $table->dropIndex(['calculation_id', 'import_status']);
            $table->dropColumn(['import_status', 'import_error']);
        });

        Schema::table('calculation_workbooks', function (Blueprint $table) {
            $table->dropIndex(['calculation_id', 'import_status']);
            $table->dropColumn(['import_status', 'import_error']);
        });
    }
};
