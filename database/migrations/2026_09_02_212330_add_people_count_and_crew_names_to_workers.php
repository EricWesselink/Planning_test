<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (! Schema::hasColumn('workers', 'people_count')) {
                $table->unsignedTinyInteger('people_count')->default(1)->after('specialty');
            }
            if (! Schema::hasColumn('workers', 'crew_names')) {
                $table->string('crew_names')->nullable()->after('people_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['people_count', 'crew_names'],
                fn (string $column): bool => Schema::hasColumn('workers', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
