<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->boolean('include_saturday')->default(true)->change();
        });

        DB::table('worker_assignments')->update(['include_saturday' => true]);
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->boolean('include_saturday')->default(false)->change();
        });
    }
};
