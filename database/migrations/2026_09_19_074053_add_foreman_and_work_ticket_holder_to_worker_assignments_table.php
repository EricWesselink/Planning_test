<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('worker_assignments', 'foreman_crew_member_id')) {
                $table->foreignId('foreman_crew_member_id')
                    ->nullable()
                    ->after('notes')
                    ->constrained('crew_members')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('worker_assignments', 'work_ticket_crew_member_id')) {
                $table->foreignId('work_ticket_crew_member_id')
                    ->nullable()
                    ->after('foreman_crew_member_id')
                    ->constrained('crew_members')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('worker_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('worker_assignments', 'work_ticket_crew_member_id')) {
                $table->dropConstrainedForeignId('work_ticket_crew_member_id');
            }
            if (Schema::hasColumn('worker_assignments', 'foreman_crew_member_id')) {
                $table->dropConstrainedForeignId('foreman_crew_member_id');
            }
        });
    }
};
