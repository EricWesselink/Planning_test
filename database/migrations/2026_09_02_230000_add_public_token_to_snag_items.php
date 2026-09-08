<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('number');
            $table->date('logged_on')->nullable()->after('due_date');
        });

        DB::table('snag_items')->whereNull('public_token')->orderBy('id')->each(function (object $snag): void {
            DB::table('snag_items')->where('id', $snag->id)->update([
                'public_token' => Str::lower(Str::random(48)),
                'logged_on' => $snag->created_at ? substr((string) $snag->created_at, 0, 10) : now()->toDateString(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('snag_items', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'logged_on']);
        });
    }
};
