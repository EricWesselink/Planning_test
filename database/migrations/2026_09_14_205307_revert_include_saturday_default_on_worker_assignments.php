<?php

use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->boolean('include_saturday')->default(false)->change();
        });

        foreach (DB::table('worker_assignments')->orderBy('id')->get() as $row) {
            DB::table('worker_assignments')->where('id', $row->id)->update([
                'include_saturday' => false,
                'planned_hours' => PlanningHours::totalHours(
                    Carbon::parse($row->start_date),
                    Carbon::parse($row->end_date),
                    (string) $row->start_time,
                    (string) $row->end_time,
                    false,
                    (bool) $row->include_sunday,
                ),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->boolean('include_saturday')->default(true)->change();
        });
    }
};
