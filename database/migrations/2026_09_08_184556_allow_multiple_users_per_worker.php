<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['worker_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['worker_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('worker_id')->references('id')->on('workers')->nullOnDelete();
            $table->foreignId('crew_member_id')
                ->nullable()
                ->after('worker_id')
                ->constrained()
                ->nullOnDelete();
            $table->unique('crew_member_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['crew_member_id']);
            $table->dropConstrainedForeignId('crew_member_id');
            $table->dropForeign(['worker_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('worker_id');
            $table->foreign('worker_id')->references('id')->on('workers')->nullOnDelete();
        });
    }
};
