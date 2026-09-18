<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\PlanningAvailabilityService;
use App\Services\PlanningBoardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_five_man_days_for_an_unbooked_person(): void
    {
        $this->makeWorker('Kees Jansen');

        $this->assertSame(5.0, $this->available());
    }

    public function test_omits_a_person_who_is_booked_every_weekday(): void
    {
        $this->makeWorker('Kees Jansen');
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-12');

        $this->assertSame(5.0, $this->available());
    }

    public function test_does_not_count_saturday_as_a_man_day(): void
    {
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-11');

        $this->assertSame(0.0, $this->available());
    }

    public function test_counts_remaining_weekdays_after_a_partial_booking(): void
    {
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-08');

        $this->assertSame(3.0, $this->available());
    }

    public function test_a_team_of_two_booked_three_days_has_four_man_days_left(): void
    {
        $team = $this->makeWorker('Wespro', ['Piet', 'Jan']);
        $item = $this->makeWorkItem();
        $this->assign($team, $item, '2026-09-07', '2026-09-09', peopleCount: 2);

        $this->assertSame(4.0, $this->available());
    }

    public function test_counts_only_the_teammate_who_still_has_free_days(): void
    {
        $team = $this->makeWorker('Jansen Vloeren', ['Piet', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $item = $this->makeWorkItem();
        $this->assign($team, $item, '2026-09-07', '2026-09-12', [$people[0]->id]);

        $this->assertSame(5.0, $this->available());
    }

    public function test_omits_inactive_workers(): void
    {
        $this->makeWorker('Kees Jansen');
        Worker::query()->create([
            'name' => 'Oud team',
            'employment_type' => 'eigen',
            'specialty' => 'Linoleum',
            'active' => false,
        ]);

        $this->assertSame(5.0, $this->available());
    }

    public function test_includes_a_team_without_a_login(): void
    {
        $this->makeWorker('Harm Wesselink');
        Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'people_count' => 3,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);

        $this->assertSame(20.0, $this->available());
    }

    public function test_omits_a_zzp_who_is_unavailable_the_whole_week(): void
    {
        $nick = $this->makeWorker('Nick Seine');
        $nick->forceFill(['employment_type' => 'zzp'])->save();
        $nick->availabilities()->create([
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-12',
            'kind' => 'unavailable',
        ]);

        $this->assertSame(0.0, $this->available());
    }

    public function test_omits_a_team_switched_fully_unavailable(): void
    {
        $peter = $this->makeWorker('Peter');
        $peter->update(['unavailable' => true]);

        $this->assertSame(0.0, $this->available());
    }

    public function test_own_staff_with_friday_off_still_count_the_other_days(): void
    {
        $peter = $this->makeWorker('Peter');
        $peter->update(['friday_off' => true]);

        $this->assertSame(4.0, $this->available());
    }

    public function test_counts_free_weekdays_across_multiple_weeks(): void
    {
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-12');

        $this->assertSame(5.0, $this->available(2));
    }

    public function test_a_half_day_counts_as_half_a_man_day(): void
    {
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-07', startTime: '08:00:00', endTime: '12:00:00');

        $this->assertSame(4.5, $this->available());
        $this->assertSame([
            'Peter — 0,5 / 5 mandagen gepland · 4,5 vrij',
        ], $this->summaries());
    }

    public function test_counts_teammates_on_separate_projects_the_same_day(): void
    {
        $team = $this->makeWorker('Wespro', ['Piet', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $first = $this->makeWorkItem();
        $second = $this->makeWorkItem();
        $this->assign($team, $first, '2026-09-07', '2026-09-07', [$people[0]->id]);
        $this->assign($team, $second, '2026-09-07', '2026-09-07', [$people[1]->id]);

        $this->assertSame(8.0, $this->available());
        $this->assertSame([
            'Wespro — 2 / 10 mandagen gepland · 8 vrij',
        ], $this->summaries());
    }

    public function test_does_not_double_count_the_same_teammate_on_two_projects(): void
    {
        $team = $this->makeWorker('Wespro', ['Piet', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $first = $this->makeWorkItem();
        $second = $this->makeWorkItem();
        $this->assign($team, $first, '2026-09-07', '2026-09-07', [$people[0]->id]);
        $this->assign($team, $second, '2026-09-07', '2026-09-07', [$people[0]->id]);

        $this->assertSame(9.0, $this->available());
        $this->assertSame([
            'Wespro — 1 / 10 mandagen gepland · 9 vrij',
        ], $this->summaries());
    }

    public function test_morning_and_afternoon_on_two_projects_make_one_man_day(): void
    {
        $team = $this->makeWorker('Wespro', ['Piet', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $first = $this->makeWorkItem();
        $second = $this->makeWorkItem();
        $this->assign($team, $first, '2026-09-07', '2026-09-07', [$people[0]->id], startTime: '08:00:00', endTime: '12:00:00');
        $this->assign($team, $second, '2026-09-07', '2026-09-07', [$people[0]->id], startTime: '12:00:00', endTime: '16:00:00');

        $this->assertSame(9.0, $this->available());
        $this->assertSame([
            'Wespro — 1 / 10 mandagen gepland · 9 vrij',
        ], $this->summaries());
    }

    public function test_does_not_count_more_people_than_the_team_has_on_one_day(): void
    {
        $team = $this->makeWorker('Wespro', ['Piet', 'Jan']);
        $first = $this->makeWorkItem();
        $second = $this->makeWorkItem();
        $this->assign($team, $first, '2026-09-07', '2026-09-07', peopleCount: 2);
        $this->assign($team, $second, '2026-09-07', '2026-09-07', peopleCount: 2);

        $this->assertSame(8.0, $this->available());
        $this->assertSame([
            'Wespro — 2 / 10 mandagen gepland · 8 vrij',
        ], $this->summaries());
    }

    public function test_lists_planned_and_remaining_man_days_per_team(): void
    {
        $this->makeWorker('Kees Jansen');
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-12');

        $this->assertSame([
            'Kees Jansen — 0 / 5 mandagen gepland · 5 vrij',
            'Peter — 5 / 5 mandagen gepland · 0 vrij',
        ], $this->summaries());
    }

    public function test_lists_a_partly_booked_team_as_planned_versus_available(): void
    {
        $team = $this->makeWorker('Wespro', ['Piet', 'Jan']);
        $item = $this->makeWorkItem();
        $this->assign($team, $item, '2026-09-07', '2026-09-09', peopleCount: 2);

        $this->assertSame([
            'Wespro — 6 / 10 mandagen gepland · 4 vrij',
        ], $this->summaries());
    }

    public function test_lists_a_team_when_only_one_teammate_is_booked(): void
    {
        $team = $this->makeWorker('Jansen Vloeren', ['Piet', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $item = $this->makeWorkItem();
        $this->assign($team, $item, '2026-09-07', '2026-09-12', [$people[0]->id]);

        $this->assertSame([
            'Jansen Vloeren — 5 / 10 mandagen gepland · 5 vrij',
        ], $this->summaries());
    }

    public function test_daily_matrix_counts_free_people_per_weekday(): void
    {
        $this->makeWorker('Kees Jansen');

        $monday = $this->cell('Kees Jansen', '2026-09-07');

        $this->assertSame('ok', $monday['tone']);
        $this->assertSame(1, $monday['free_count']);
        $this->assertSame('1 vrij', $monday['label']);
        $this->assertSame('vrij', $monday['people'][0]['detail']);
        $this->assertTrue($monday['people'][0]['selectable']);
        $this->assertSame(['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'], $this->dayLabels());
    }

    public function test_daily_matrix_keeps_a_half_day_selectable(): void
    {
        $peter = $this->makeWorker('Peter');
        $item = $this->makeWorkItem();
        $this->assign($peter, $item, '2026-09-07', '2026-09-07', startTime: '08:00:00', endTime: '12:00:00');

        $monday = $this->cell('Peter', '2026-09-07');

        $this->assertSame('partial', $monday['tone']);
        $this->assertSame(1, $monday['free_count']);
        $this->assertSame('nog 4u vrij', $monday['people'][0]['detail']);
        $this->assertSame(4.0, $monday['people'][0]['remaining_hours']);
        $this->assertTrue($monday['people'][0]['selectable']);
        $this->assertSame('ok', $this->cell('Peter', '2026-09-08')['tone']);
    }

    public function test_daily_matrix_marks_friday_off_as_away(): void
    {
        $peter = $this->makeWorker('Peter');
        $peter->update(['friday_off' => true]);

        $friday = $this->cell('Peter', '2026-09-11');

        $this->assertSame('away', $friday['tone']);
        $this->assertSame(0, $friday['free_count']);
        $this->assertSame('0 vrij', $friday['label']);
        $this->assertSame('Vrij', $friday['people'][0]['detail']);
        $this->assertFalse($friday['people'][0]['selectable']);
        $this->assertSame('ok', $this->cell('Peter', '2026-09-10')['tone']);
    }

    public function test_daily_matrix_shows_named_teammates_who_are_still_free(): void
    {
        $team = $this->makeWorker('Wespro', ['Piet', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $item = $this->makeWorkItem();
        $this->assign($team, $item, '2026-09-08', '2026-09-08', [$people[0]->id]);

        $tuesday = $this->cell('Wespro', '2026-09-08');

        $this->assertSame('partial', $tuesday['tone']);
        $this->assertSame(1, $tuesday['free_count']);
        $this->assertSame('Piet', $tuesday['people'][0]['name']);
        $this->assertSame('busy', $tuesday['people'][0]['status']);
        $this->assertSame('8u ingepland', $tuesday['people'][0]['detail']);
        $this->assertSame('Jan', $tuesday['people'][1]['name']);
        $this->assertSame('free', $tuesday['people'][1]['status']);
        $this->assertTrue($tuesday['people'][1]['selectable']);
    }

    /**
     * @return list<string>
     */
    private function dayLabels(int $weeks = 1): array
    {
        return array_column($this->overview($weeks)['days'], 'label');
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(string $name, string $date, int $weeks = 1): array
    {
        $team = collect($this->overview($weeks)['teams'])->firstWhere('label', $name);
        $this->assertIsArray($team);
        $this->assertArrayHasKey($date, $team['days']);

        return $team['days'][$date];
    }

    /**
     * @return array{days: list<array<string, string>>, teams: list<array<string, mixed>>}
     */
    private function overview(int $weeks = 1): array
    {
        $days = app(PlanningBoardService::class)->weekDays(Carbon::parse('2026-09-07'), $weeks);

        return app(PlanningAvailabilityService::class)->overview($days);
    }

    private function available(int $weeks = 1): float
    {
        $days = app(PlanningBoardService::class)->weekDays(Carbon::parse('2026-09-07'), $weeks);

        return app(PlanningAvailabilityService::class)->remainingManDays($days);
    }

    /**
     * @return list<string>
     */
    private function summaries(int $weeks = 1): array
    {
        $days = app(PlanningBoardService::class)->weekDays(Carbon::parse('2026-09-07'), $weeks);

        return array_map(
            fn (array $team): string => $team['summary'],
            app(PlanningAvailabilityService::class)->forDays($days),
        );
    }

    /**
     * @param  list<string>  $names
     */
    private function makeWorker(string $name, array $names = []): Worker
    {
        $members = [];
        foreach ($names as $member) {
            $members[] = ['name' => $member, 'phone' => ''];
        }

        $worker = Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'people_count' => $names === [] ? 1 : count($names),
            'crew_members' => $members !== [] ? $members : null,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $this->giveLogin($worker);

        return $worker;
    }

    private function giveLogin(Worker $worker): void
    {
        User::factory()->vakman($worker->id)->create([
            'name' => $worker->name,
        ]);
    }

    private function makeWorkItem(): WorkItem
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => '2602000'.fake()->unique()->numerify('##'),
            'customer_id' => $customer->id,
            'name' => 'Testwerk',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }

    /**
     * @param  list<int>  $crewIds
     */
    private function assign(
        Worker $worker,
        WorkItem $item,
        string $start,
        string $end,
        array $crewIds = [],
        ?int $peopleCount = null,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
    ): WorkerAssignment {
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'people_count' => $peopleCount ?? max(1, count($crewIds) ?: 1),
        ]);
        $assignment->applySchedule(
            Carbon::parse($start),
            Carbon::parse($end),
            $startTime,
            $endTime,
        );
        $assignment->save();
        if ($crewIds !== []) {
            $assignment->syncPresentCrew($crewIds);
        }

        return $assignment;
    }
}
