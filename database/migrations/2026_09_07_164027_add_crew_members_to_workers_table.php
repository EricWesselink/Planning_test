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
            if (! Schema::hasColumn('workers', 'crew_members')) {
                $table->json('crew_members')->nullable()->after('crew_names');
            }
        });

        foreach (DB::table('workers')->select('id', 'crew_names', 'phone', 'people_count')->orderBy('id')->get() as $worker) {
            $names = array_values(array_filter(
                array_map(
                    static fn (string $name): string => trim($name),
                    explode(',', (string) $worker->crew_names),
                ),
                static fn (string $name): bool => $name !== '',
            ));
            $phone = trim((string) $worker->phone);
            if ($names === [] && $phone === '') {
                continue;
            }

            $count = max(1, (int) $worker->people_count, count($names));
            $members = [];
            for ($index = 0; $index < $count; $index++) {
                $members[] = [
                    'name' => $names[$index] ?? '',
                    'phone' => $index === 0 ? $phone : '',
                ];
            }

            $values = [
                'crew_members' => json_encode($members, JSON_UNESCAPED_UNICODE),
            ];
            if ($count !== (int) $worker->people_count) {
                $values['people_count'] = $count;
            }

            DB::table('workers')->where('id', $worker->id)->update($values);
        }
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (Schema::hasColumn('workers', 'crew_members')) {
                $table->dropColumn('crew_members');
            }
        });
    }
};
