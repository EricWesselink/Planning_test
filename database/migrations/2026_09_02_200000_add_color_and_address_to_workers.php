<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('email');
            $table->string('address')->nullable()->after('color');
            $table->string('postal_code', 16)->nullable()->after('address');
            $table->string('city')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['color', 'address', 'postal_code', 'city']);
        });
    }
};
