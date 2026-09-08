<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('');
            $table->string('phone', 64)->default('');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['worker_id', 'sort_order']);
        });

        Schema::create('crew_member_worker_assignment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crew_member_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['worker_assignment_id', 'crew_member_id'], 'assignment_crew_member_unique');
        });

        foreach (DB::table('workers')->select('id', 'crew_members', 'crew_names', 'phone', 'people_count')->orderBy('id')->get() as $worker) {
            $members = $this->membersFromWorker($worker);
            foreach ($members as $index => $member) {
                DB::table('crew_members')->insert([
                    'worker_id' => $worker->id,
                    'name' => $member['name'],
                    'phone' => $member['phone'],
                    'sort_order' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_member_worker_assignment');
        Schema::dropIfExists('crew_members');
    }

    /**
     * @return list<array{name: string, phone: string}>
     */
    private function membersFromWorker(object $worker): array
    {
        $raw = $worker->crew_members;
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $count = max(1, (int) $worker->people_count);
        $members = [];

        if (is_array($decoded)) {
            foreach ($decoded as $member) {
                if (! is_array($member)) {
                    continue;
                }
                $members[] = [
                    'name' => trim((string) ($member['name'] ?? '')),
                    'phone' => trim((string) ($member['phone'] ?? '')),
                ];
            }
        }

        if ($members === []) {
            $names = array_values(array_filter(
                array_map(
                    static fn (string $name): string => trim($name),
                    explode(',', (string) $worker->crew_names),
                ),
                static fn (string $name): bool => $name !== '',
            ));
            $phone = trim((string) $worker->phone);
            foreach ($names as $index => $name) {
                $members[] = [
                    'name' => $name,
                    'phone' => $index === 0 ? $phone : '',
                ];
            }
        }

        $count = max($count, count($members));
        while (count($members) < $count) {
            $members[] = ['name' => '', 'phone' => ''];
        }

        return array_values(array_slice($members, 0, $count));
    }
};
