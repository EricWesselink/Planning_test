<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurement_form_rows', function (Blueprint $table) {
            $table->string('available_location', 16)->nullable()->after('available_on_site');
        });
    }

    public function down(): void
    {
        Schema::table('measurement_form_rows', function (Blueprint $table) {
            $table->dropColumn('available_location');
        });
    }
};
