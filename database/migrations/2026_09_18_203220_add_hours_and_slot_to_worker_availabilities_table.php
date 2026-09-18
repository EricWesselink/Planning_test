<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_availabilities', function (Blueprint $table) {
            $table->decimal('hours', 5, 2)->default(8)->after('kind');
            $table->string('slot', 16)->default('full')->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('worker_availabilities', function (Blueprint $table) {
            $table->dropColumn(['hours', 'slot']);
        });
    }
};
