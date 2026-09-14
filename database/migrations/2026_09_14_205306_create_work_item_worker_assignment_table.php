<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_worker_assignment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['worker_assignment_id', 'work_item_id'], 'assignment_work_item_unique');
        });

        $now = now();
        foreach (DB::table('worker_assignments')->orderBy('id')->get(['id', 'work_item_id']) as $row) {
            if (! $row->work_item_id) {
                continue;
            }

            DB::table('work_item_worker_assignment')->insert([
                'worker_assignment_id' => $row->id,
                'work_item_id' => $row->work_item_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_worker_assignment');
    }
};
