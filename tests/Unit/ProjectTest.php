<?php

namespace Tests\Unit;

use App\Models\Project;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    #[DataProvider('remainingDayCases')]
    public function test_remaining_days_label_counts_from_today_to_klaar(?string $klaarDate, ?string $label): void
    {
        $project = Project::make(['planned_end_date' => $klaarDate]);

        $this->assertSame($label, $project->remainingDaysLabel(Carbon::parse('2026-09-10')));
    }

    public function test_planning_board_query_opens_the_work_period(): void
    {
        $project = Project::make([
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-18',
        ]);
        $project->id = 12;

        $this->assertSame([
            'project_id' => 12,
            'week' => '2026-09-14',
            'period' => 'work',
        ], $project->planningBoardQuery());
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function remainingDayCases(): array
    {
        return [
            'no klaar date' => [null, null],
            'today' => ['2026-09-10', 'Vandaag klaar'],
            'one day left' => ['2026-09-11', 'Nog 1 dag'],
            'seven days left' => ['2026-09-17', 'Nog 7 dagen'],
            'one day late' => ['2026-09-09', '1 dag te laat'],
            'three days late' => ['2026-09-07', '3 dagen te laat'],
        ];
    }
}
