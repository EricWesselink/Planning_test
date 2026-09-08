<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('worker_assignments', 'start_time')) {
            Schema::table('worker_assignments', function (Blueprint $table) {
                $table->time('start_time')->default('08:00:00')->after('end_date');
                $table->time('end_time')->default('16:00:00')->after('start_time');
                $table->decimal('planned_hours', 8, 2)->default(8)->after('hours_per_day');
            });
        }

        if (! Schema::hasColumn('crew_member_worker_assignment', 'start_time')) {
            Schema::table('crew_member_worker_assignment', function (Blueprint $table) {
                $table->time('start_time')->nullable()->after('crew_member_id');
                $table->time('end_time')->nullable()->after('start_time');
                $table->decimal('planned_hours', 8, 2)->nullable()->after('end_time');
            });
        }

        foreach (DB::table('worker_assignments')->orderBy('id')->get() as $assignment) {
            $hoursPerDay = (float) ($assignment->hours_per_day ?: 8);
            $start = Carbon::parse($assignment->start_date)->startOfDay();
            $end = Carbon::parse($assignment->end_date)->startOfDay();
            $days = 0;
            $day = $start->copy();
            while ($day->lte($end)) {
                $days++;
                $day->addDay();
            }

            DB::table('worker_assignments')->where('id', $assignment->id)->update([
                'start_time' => $assignment->start_time ?: '08:00:00',
                'end_time' => $assignment->end_time ?: '16:00:00',
                'planned_hours' => $hoursPerDay * max(1, $days),
            ]);
        }

        foreach (DB::table('crew_member_worker_assignment')->orderBy('id')->get() as $pivot) {
            $assignment = DB::table('worker_assignments')->where('id', $pivot->worker_assignment_id)->first();
            if (! $assignment) {
                continue;
            }

            DB::table('crew_member_worker_assignment')->where('id', $pivot->id)->update([
                'start_time' => $assignment->start_time ?: '08:00:00',
                'end_time' => $assignment->end_time ?: '16:00:00',
                'planned_hours' => $assignment->planned_hours ?: 8,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crew_member_worker_assignment', 'start_time')) {
            Schema::table('crew_member_worker_assignment', function (Blueprint $table) {
                $table->dropColumn(['start_time', 'end_time', 'planned_hours']);
            });
        }

        if (Schema::hasColumn('worker_assignments', 'start_time')) {
            Schema::table('worker_assignments', function (Blueprint $table) {
                $table->dropColumn(['start_time', 'end_time', 'planned_hours']);
            });
        }
    }
};
