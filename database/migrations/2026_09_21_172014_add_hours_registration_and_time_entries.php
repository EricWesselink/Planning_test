<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->boolean('registers_hours')->default(true)->after('unavailable');
        });

        Schema::table('crew_members', function (Blueprint $table) {
            $table->boolean('registers_hours')->default(true)->after('active');
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->string('origin', 16)->default('planned')->after('is_provisional');
        });

        Schema::table('work_progress_entries', function (Blueprint $table) {
            $table->foreignId('crew_member_id')->nullable()->after('worker_id')->constrained()->nullOnDelete();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->restrictOnDelete();
            $table->foreignId('crew_member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worker_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('planned_hours', 8, 2)->default(0);
            $table->decimal('hours', 8, 2);
            $table->text('note')->nullable();
            $table->string('status', 32)->default('ingediend');
            $table->boolean('is_unplanned')->default(false);
            $table->string('identity_key', 80)->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('work_progress_entry_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('actual_assignment_id')->nullable()->constrained('worker_assignments')->nullOnDelete();
            $table->timestamps();

            $table->index(['worker_id', 'date']);
            $table->index(['crew_member_id', 'date']);
            $table->index(['status', 'date']);
            $table->index(['project_id', 'date']);
        });

        DB::table('workers')->where('employment_type', '!=', 'eigen')->update(['registers_hours' => false]);

        $hourlyIds = DB::table('worker_rates')
            ->where('specialty', 'uurtarief')
            ->where('unit', 'uren')
            ->pluck('worker_id');
        if ($hourlyIds->isNotEmpty()) {
            DB::table('workers')->whereIn('id', $hourlyIds)->update(['registers_hours' => true]);
        }

        $externalWorkerIds = DB::table('workers')->where('registers_hours', false)->pluck('id');
        if ($externalWorkerIds->isNotEmpty()) {
            DB::table('crew_members')->whereIn('worker_id', $externalWorkerIds)->update(['registers_hours' => false]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');

        Schema::table('work_progress_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crew_member_id');
        });

        Schema::table('worker_assignments', function (Blueprint $table) {
            $table->dropColumn('origin');
        });

        Schema::table('crew_members', function (Blueprint $table) {
            $table->dropColumn('registers_hours');
        });

        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('registers_hours');
        });
    }
};
