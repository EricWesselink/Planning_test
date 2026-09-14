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
            if (! Schema::hasColumn('worker_assignments', 'include_saturday')) {
                $table->boolean('include_saturday')->default(false)->after('end_time');
            }
            if (! Schema::hasColumn('worker_assignments', 'include_sunday')) {
                $table->boolean('include_sunday')->default(false)->after('include_saturday');
            }
        });

        if (Schema::hasColumn('worker_assignments', 'include_weekends')) {
            foreach (DB::table('worker_assignments')->orderBy('id')->get(['id', 'include_weekends']) as $row) {
                DB::table('worker_assignments')->where('id', $row->id)->update([
                    'include_saturday' => (bool) $row->include_weekends,
                    'include_sunday' => (bool) $row->include_weekends,
                ]);
            }

            Schema::table('worker_assignments', function (Blueprint $table) {
                $table->dropColumn('include_weekends');
            });
        }
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('worker_assignments', 'include_weekends')) {
                $table->boolean('include_weekends')->default(false)->after('end_time');
            }
        });

        if (Schema::hasColumn('worker_assignments', 'include_saturday')) {
            foreach (DB::table('worker_assignments')->orderBy('id')->get(['id', 'include_saturday', 'include_sunday']) as $row) {
                DB::table('worker_assignments')->where('id', $row->id)->update([
                    'include_weekends' => (bool) $row->include_saturday || (bool) $row->include_sunday,
                ]);
            }
        }

        Schema::table('worker_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('worker_assignments', 'include_saturday')) {
                $table->dropColumn('include_saturday');
            }
            if (Schema::hasColumn('worker_assignments', 'include_sunday')) {
                $table->dropColumn('include_sunday');
            }
        });
    }
};
