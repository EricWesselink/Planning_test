<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_areas', function (Blueprint $table) {
            $table->decimal('square_meters', 12, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_areas', function (Blueprint $table) {
            $table->decimal('square_meters', 12, 2)->default(0)->nullable(false)->change();
        });
    }
};
