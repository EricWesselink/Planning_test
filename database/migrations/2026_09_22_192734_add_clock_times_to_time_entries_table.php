<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('time_entries', 'start_time')) {
                $table->time('start_time')->nullable()->after('hours');
            }
            if (! Schema::hasColumn('time_entries', 'end_time')) {
                $table->time('end_time')->nullable()->after('start_time');
            }
            if (! Schema::hasColumn('time_entries', 'break_minutes')) {
                $table->unsignedSmallInteger('break_minutes')->nullable()->after('end_time');
            }
            if (! Schema::hasColumn('time_entries', 'approved_start_time')) {
                $table->time('approved_start_time')->nullable()->after('approved_hours');
            }
            if (! Schema::hasColumn('time_entries', 'approved_end_time')) {
                $table->time('approved_end_time')->nullable()->after('approved_start_time');
            }
            if (! Schema::hasColumn('time_entries', 'approved_break_minutes')) {
                $table->unsignedSmallInteger('approved_break_minutes')->nullable()->after('approved_end_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('time_entries', 'approved_break_minutes') ? 'approved_break_minutes' : null,
                Schema::hasColumn('time_entries', 'approved_end_time') ? 'approved_end_time' : null,
                Schema::hasColumn('time_entries', 'approved_start_time') ? 'approved_start_time' : null,
                Schema::hasColumn('time_entries', 'break_minutes') ? 'break_minutes' : null,
                Schema::hasColumn('time_entries', 'end_time') ? 'end_time' : null,
                Schema::hasColumn('time_entries', 'start_time') ? 'start_time' : null,
            ]));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
