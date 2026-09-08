<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'reported' => 'reported_done',
            'approved' => 'closed',
        ];

        foreach ($map as $from => $to) {
            DB::table('snag_items')->where('status', $from)->update(['status' => $to]);
            DB::table('snag_history')->where('old_status', $from)->update(['old_status' => $to]);
            DB::table('snag_history')->where('new_status', $from)->update(['new_status' => $to]);
        }

        DB::table('snag_history')->where('action', 'approved')->update(['action' => 'closed']);
        DB::table('snag_history')->where('action', 'reported')->update(['action' => 'reported_done']);
    }

    public function down(): void
    {
        $map = [
            'reported_done' => 'reported',
            'closed' => 'approved',
        ];

        foreach ($map as $from => $to) {
            DB::table('snag_items')->where('status', $from)->update(['status' => $to]);
            DB::table('snag_history')->where('old_status', $from)->update(['old_status' => $to]);
            DB::table('snag_history')->where('new_status', $from)->update(['new_status' => $to]);
        }

        DB::table('snag_history')->where('action', 'closed')->update(['action' => 'approved']);
        DB::table('snag_history')->where('action', 'reported_done')->update(['action' => 'reported']);
    }
};
