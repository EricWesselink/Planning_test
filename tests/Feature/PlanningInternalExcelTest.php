<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class PlanningInternalExcelTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_page_offers_intern_excel_with_year_choices(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Intern Excel')
            ->assertSee('name="year"', false)
            ->assertSee('>2026</option>', false)
            ->assertDontSee('Huidige week')
            ->assertDontSee('Komende 4 weken')
            ->assertSee('action="'.url('/planning/excel').'"', false);
    }

    public function test_guest_cannot_download_intern_excel(): void
    {
        $this->get(route('planning.excel', ['year' => 2026]))
            ->assertRedirect(route('login'));
    }

    public function test_rejects_a_year_outside_the_allowed_range(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('planning', ['week' => '2026-09-07']))
            ->get(route('planning.excel', ['year' => 1999]))
            ->assertRedirect(route('planning', ['week' => '2026-09-07']))
            ->assertSessionHasErrors('year');
    }

    public function test_year_export_puts_each_vakman_on_a_row_with_numeric_hours(): void
    {
        $user = User::factory()->create();
        $this->seedProjectWithHours();

        $response = $this->actingAs($user)
            ->get(route('planning.excel', ['year' => 2026]));

        $response
            ->assertOk()
            ->assertDownload('Nicon-planning-2026.xlsx')
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->sheetFrom($response);
        $monday = $this->dayColumn(37, 0);

        $this->assertSame('Planning Nicon', $sheet->getTitle());
        $this->assertSame('Bron', $sheet->getCell('A2')->getValue());
        $this->assertSame('Aannemer/ klant', $sheet->getCell('B2')->getValue());
        $this->assertSame('Naam project', $sheet->getCell('C2')->getValue());
        $this->assertSame('Plaats', $sheet->getCell('D2')->getValue());
        $this->assertSame('Soort stoffering', $sheet->getCell('E2')->getValue());
        $this->assertSame('m2', $sheet->getCell('F2')->getValue());
        $this->assertSame('Team / vakman', $sheet->getCell('G2')->getValue());
        $this->assertSame('Begroot uren', $sheet->getCell('H2')->getValue());
        $this->assertSame('Gepland uren', $sheet->getCell('I2')->getValue());
        $this->assertSame('Verschil', $sheet->getCell('J2')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('K1')->getValue());
        $this->assertSame('ma', $sheet->getCell('K2')->getValue());
        $this->assertSame('vr', $sheet->getCell('O2')->getValue());
        $this->assertSame('wk', $sheet->getCell('P2')->getValue());
        $this->assertSame(37, (int) $sheet->getCell($monday.'1')->getValue());
        $this->assertSame(53, (int) $sheet->getCell($this->dayColumn(53, 0).'1')->getValue());
        $this->assertSame('K3', $sheet->getFreezePane());
        $this->assertGreaterThan(20.0, $sheet->getColumnDimension('G')->getWidth());
        $this->assertGreaterThan(10.0, $sheet->getColumnDimension('H')->getWidth());
        $this->assertGreaterThan(10.0, $sheet->getColumnDimension('I')->getWidth());
        $this->assertGreaterThan(10.0, $sheet->getColumnDimension('J')->getWidth());
        $this->assertEqualsWithDelta(4.0, $sheet->getColumnDimension('K')->getWidth(), 0.2);
        $this->assertSame('C00000', $sheet->getStyle('A2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFFFFF', $sheet->getStyle('A2')->getFont()->getColor()->getRGB());
        $this->assertSame('D8D8D8', $sheet->getStyle('K1')->getFill()->getStartColor()->getRGB());

        $projectRow = $this->projectRow($sheet, 'Laakse Tuinen Amersfoort');
        $this->assertSame('Nicon Vloeren', $sheet->getCell('A'.$projectRow)->getValue());
        $this->assertSame('Gemeente Amersfoort', $sheet->getCell('B'.$projectRow)->getValue());
        $this->assertSame('Amersfoort', $sheet->getCell('D'.$projectRow)->getValue());
        $this->assertSame(3835, (int) $sheet->getCell('F'.$projectRow)->getValue());
        $this->assertSame('', (string) $sheet->getCell($monday.$projectRow)->getValue());
        $this->assertSame(['Laakse Tuinen Amersfoort', 'School Zwolle'], $this->projectNames($sheet));

        $openRow = $this->projectRow($sheet, 'School Zwolle');
        $this->assertSame('Schoolbestuur', $sheet->getCell('B'.$openRow)->getValue());
        $this->assertSame('Zwolle', $sheet->getCell('D'.$openRow)->getValue());
        $this->assertSame('Linoleum', $sheet->getCell('E'.$openRow)->getValue());
        $this->assertSame(200, (int) $sheet->getCell('F'.$openRow)->getValue());
        $this->assertSame('', (string) $sheet->getCell('G'.$openRow)->getValue());
        $this->assertSame(0, $this->hoursAt($sheet, 'School Zwolle', 'Totaal werk', 37, 0));
        $this->assertHoursFormula($sheet, $this->totalHoursCell($sheet, 'School Zwolle', 'Totaal werk'), 0);

        $this->assertSame(8, $this->hoursAt($sheet, 'Laakse Tuinen Amersfoort', 'Albert', 37, 0));
        $this->assertSame(4, $this->hoursAt($sheet, 'Laakse Tuinen Amersfoort', 'Albert', 37, 1));
        $this->assertNull($this->hoursAt($sheet, 'Laakse Tuinen Amersfoort', 'Albert', 37, 2));
        $this->assertSame(8, $this->hoursAt($sheet, 'Laakse Tuinen Amersfoort', 'Nick', 37, 0));
        $this->assertNull($this->hoursAt($sheet, 'Laakse Tuinen Amersfoort', 'Nick', 37, 1));
        $this->assertSame(16, $this->hoursAt($sheet, 'Laakse Tuinen Amersfoort', 'Totaal werk', 37, 0));
        $this->assertSame(4, $this->hoursAt($sheet, 'Laakse Tuinen Amersfoort', 'Totaal werk', 37, 1));
        $this->assertHoursFormula($sheet, $this->weekTotalColumn(37).$this->rowInProject($sheet, 'Laakse Tuinen Amersfoort', 'Albert'), 12);
        $this->assertHoursFormula($sheet, $this->totalHoursCell($sheet, 'Laakse Tuinen Amersfoort', 'Albert'), 12);
        $this->assertHoursFormula($sheet, $this->totalHoursCell($sheet, 'Laakse Tuinen Amersfoort', 'Nick'), 8);
        $this->assertHoursFormula($sheet, $this->totalHoursCell($sheet, 'Laakse Tuinen Amersfoort', 'Totaal werk'), 20);
        $this->assertSame('—', $sheet->getCell('H'.$this->rowInProject($sheet, 'Laakse Tuinen Amersfoort', 'Albert'))->getValue());
        $this->assertSame('—', $sheet->getCell('H'.$this->rowInProject($sheet, 'Laakse Tuinen Amersfoort', 'Totaal werk'))->getValue());
        $this->assertTrue($sheet->getStyle('A'.$projectRow)->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('G'.$this->rowInProject($sheet, 'Laakse Tuinen Amersfoort', 'Totaal werk'))->getFont()->getBold());
        $this->assertSame('F8CBAD', $sheet->getStyle('G'.$this->rowInProject($sheet, 'Laakse Tuinen Amersfoort', 'Totaal werk'))->getFill()->getStartColor()->getRGB());
    }

    public function test_two_crew_members_each_get_their_own_hour_row(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Ploeg Noord',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Nick', 'phone' => ''],
                ['name' => 'Mahmoud', 'phone' => ''],
            ],
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject('TWC Studentenhuisvesting');
        $people = $worker->crewPeople()->orderBy('sort_order')->get();
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00', $people->modelKeys());

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame(8, $this->hoursAt($sheet, 'TWC Studentenhuisvesting', 'Ploeg Noord (Nick)', 37, 0));
        $this->assertSame(8, $this->hoursAt($sheet, 'TWC Studentenhuisvesting', 'Ploeg Noord (Mahmoud)', 37, 0));
        $this->assertSame(16, $this->hoursAt($sheet, 'TWC Studentenhuisvesting', 'Totaal werk', 37, 0));
        $this->assertNotContains('Totaal Ploeg Noord', $this->labelsInProject($sheet, 'TWC Studentenhuisvesting'));
        $this->assertNotContains('Ploeg Noord', $this->labelsInProject($sheet, 'TWC Studentenhuisvesting'));
    }

    public function test_zzp_company_name_prefixes_each_crew_member_row(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Wepro',
            'company' => 'Wepro',
            'employment_type' => 'zzp',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => ''],
                ['name' => 'Harm Wesselink', 'phone' => ''],
            ],
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject('Gezondheidscentrum Laren', [
            'city' => 'Laren',
        ]);
        $people = $worker->crewPeople()->orderBy('sort_order')->get();
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00', $people->modelKeys());

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame('Wepro (Eric W.)', $sheet->getCell('G'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Wepro (Eric W.)'))->getValue());
        $this->assertSame('Wepro (Harm W.)', $sheet->getCell('G'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Wepro (Harm W.)'))->getValue());
        $this->assertSame(8, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Wepro (Eric W.)', 37, 0));
        $this->assertSame(8, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Wepro (Harm W.)', 37, 0));
        $this->assertSame(16, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Totaal werk', 37, 0));
        $this->assertHoursFormula($sheet, $this->totalHoursCell($sheet, 'Gezondheidscentrum Laren', 'Totaal werk'), 16);
        $this->assertNotContains('Totaal Wepro', $this->labelsInProject($sheet, 'Gezondheidscentrum Laren'));
    }

    public function test_vakmen_on_the_same_day_keep_their_hours_on_separate_rows(): void
    {
        $user = User::factory()->create();
        [$nick, $project, $item] = $this->makeProjectWorker('Nick', 'Laakse Tuinen');
        [$eric] = $this->makeProjectWorker('Eric V', 'Andere klus', ['project_number' => '260200091']);
        $team = Team::query()->create(['name' => 'Team 1', 'active' => true]);
        $team->workers()->attach([$nick->id, $eric->id]);
        $this->assign($nick, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($eric, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '12:00:00');

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame('', (string) $sheet->getCell('G'.$this->projectRow($sheet, 'Laakse Tuinen'))->getValue());
        $this->assertSame('Team 1 (Eric V.)', $sheet->getCell('G'.$this->rowInProject($sheet, 'Laakse Tuinen', 'Team 1 (Eric V.)'))->getValue());
        $this->assertSame('Team 1 (Nick)', $sheet->getCell('G'.$this->rowInProject($sheet, 'Laakse Tuinen', 'Team 1 (Nick)'))->getValue());
        $this->assertSame(8, $this->hoursAt($sheet, 'Laakse Tuinen', 'Team 1 (Nick)', 37, 0));
        $this->assertSame(4, $this->hoursAt($sheet, 'Laakse Tuinen', 'Team 1 (Eric V.)', 37, 0));
        $this->assertSame(12, $this->hoursAt($sheet, 'Laakse Tuinen', 'Totaal werk', 37, 0));
        $this->assertHoursFormula(
            $sheet,
            $this->dayColumn(37, 0).$this->rowInProject($sheet, 'Laakse Tuinen', 'Totaal werk'),
            12,
        );
        $this->assertHoursFormula(
            $sheet,
            $this->totalHoursCell($sheet, 'Laakse Tuinen', 'Totaal werk'),
            12,
        );
        $this->assertNotContains('Totaal Team 1', $this->labelsInProject($sheet, 'Laakse Tuinen'));
    }

    public function test_one_vakman_split_across_two_projects_keeps_hours_on_each_project_row(): void
    {
        $user = User::factory()->create();
        [$nick, $first, $firstItem] = $this->makeProjectWorker('Nick', 'Project A', [
            'project_number' => '260200090',
        ]);
        [$second, $secondItem] = $this->makeProject('Project B', [
            'project_number' => '260200091',
            'city' => 'Laren',
        ]);
        $this->assign($nick, $first, $firstItem, '2026-09-07', '2026-09-07', '08:00:00', '12:00:00');
        $this->assign($nick, $second, $secondItem, '2026-09-07', '2026-09-07', '12:00:00', '16:00:00');

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame(4, $this->hoursAt($sheet, 'Project A', 'Nick', 37, 0));
        $this->assertSame(4, $this->hoursAt($sheet, 'Project B', 'Nick', 37, 0));
    }

    public function test_blocks_on_the_same_project_and_day_are_added_together(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Albert', 'Laakse Tuinen');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '12:00:00');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '12:00:00', '16:00:00');

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame(8, $this->hoursAt($sheet, 'Laakse Tuinen', 'Albert', 37, 0));
    }

    public function test_past_days_use_logged_hours_for_that_worker_instead_of_planning(): void
    {
        $this->travelTo('2026-09-10 09:00:00');
        $user = User::factory()->create();
        [$albert, $project, $item] = $this->makeProjectWorker('Albert', 'Laakse Tuinen');
        $this->assign($albert, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($albert, $project, $item, '2026-09-11', '2026-09-11', '08:00:00', '16:00:00');
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_id' => $albert->id,
            'date' => '2026-09-07',
            'completed_quantity' => 40,
            'unit' => 'm2',
            'worked_hours' => 6,
            'created_by' => $user->id,
        ]);

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame(6, $this->hoursAt($sheet, 'Laakse Tuinen', 'Albert', 37, 0));
        $this->assertSame(8, $this->hoursAt($sheet, 'Laakse Tuinen', 'Albert', 37, 4));
    }

    public function test_people_count_without_crew_names_multiplies_the_stored_hours(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Ploeg Zuid', 'School Zwolle');
        $assignment = $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $assignment->forceFill(['people_count' => 3])->save();

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame(24, $this->hoursAt($sheet, 'School Zwolle', 'Ploeg Zuid', 37, 0));
    }

    public function test_team_column_uses_stored_teams_and_indents_vakmen(): void
    {
        $user = User::factory()->create();
        $teamOne = Team::query()->create(['name' => 'Team 1', 'active' => true]);
        $teamTwo = Team::query()->create(['name' => 'Team 2', 'active' => true]);
        [$eric, $project, $item] = $this->makeProjectWorker('Eric Wesselink', 'Gezondheidscentrum Laren', [
            'city' => 'Laren',
        ]);
        $nick = Worker::query()->create([
            'name' => 'Nick',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $teamOne->workers()->attach($eric->id);
        $teamTwo->workers()->attach($nick->id);
        $this->assign($eric, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($nick, $project, $item, '2026-09-08', '2026-09-08', '08:00:00', '12:00:00');

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $projectRow = $this->projectRow($sheet, 'Gezondheidscentrum Laren');
        $this->assertSame('', (string) $sheet->getCell('G'.$projectRow)->getValue());
        $this->assertSame('Team 1 (Eric W.)', $sheet->getCell('G'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Team 1 (Eric W.)'))->getValue());
        $this->assertSame('Team 2 (Nick)', $sheet->getCell('G'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Team 2 (Nick)'))->getValue());
        $this->assertTrue($sheet->getStyle('G'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Totaal Team 1'))->getFont()->getBold());
        $this->assertSame('BDD7EE', $sheet->getStyle('G'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Totaal Team 1'))->getFill()->getStartColor()->getRGB());
        $this->assertSame('F8CBAD', $sheet->getStyle('G'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Totaal werk'))->getFill()->getStartColor()->getRGB());
        $this->assertSame(8, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Team 1 (Eric W.)', 37, 0));
        $this->assertSame(4, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Team 2 (Nick)', 37, 1));
        $this->assertSame(8, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Totaal Team 1', 37, 0));
        $this->assertSame(4, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Totaal Team 2', 37, 1));
        $this->assertSame(8, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Totaal werk', 37, 0));
        $this->assertSame(4, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Totaal werk', 37, 1));
        $this->assertHoursFormula(
            $sheet,
            $this->totalHoursCell($sheet, 'Gezondheidscentrum Laren', 'Totaal werk'),
            12,
        );
        $this->assertSame('—', $sheet->getCell('H'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Team 1 (Eric W.)'))->getValue());
        $this->assertSame('—', $sheet->getCell('H'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Totaal Team 1'))->getValue());
        $this->assertSame('—', $sheet->getCell('J'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Totaal Team 1'))->getValue());
        $this->assertHoursFormula(
            $sheet,
            $this->weekTotalColumn(37).$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Totaal werk'),
            12,
        );
    }

    public function test_service_extra_and_small_works_are_exported(): void
    {
        $user = User::factory()->create();
        [$eric, $service, $serviceItem] = $this->makeProjectWorker('Eric', 'herstel', [
            'kind' => ProjectKind::Service,
            'name' => 'herstel',
            'city' => 'Deventer',
            'project_number' => '260200090',
        ]);
        [$nick, $parent, $parentItem] = $this->makeProjectWorker('Nick', 'Gezondheidscentrum Laren', [
            'project_number' => '260200091',
            'city' => 'Laren',
        ]);
        $extra = WorkItem::query()->create([
            'project_id' => $parent->id,
            'name' => 'extra egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 0,
            'is_extra_work' => true,
            'small_work_type' => SmallWorkType::Extra,
            'status' => 'in_uitvoering',
        ]);
        [$linda, $klein, $kleinItem] = $this->makeProjectWorker('Linda', 'plinten vervangen', [
            'kind' => ProjectKind::Klein,
            'name' => 'plinten vervangen',
            'city' => 'Kampen',
            'project_number' => '260200092',
        ]);
        $this->assign($eric, $service, $serviceItem, '2026-09-07', '2026-09-07', '08:00:00', '12:00:00');
        $this->assign($nick, $parent, $extra, '2026-09-08', '2026-09-08', '08:00:00', '12:00:00');
        $this->assign($nick, $parent, $parentItem, '2026-09-09', '2026-09-09', '08:00:00', '16:00:00');
        $this->assign($linda, $klein, $kleinItem, '2026-09-10', '2026-09-10', '08:00:00', '16:00:00');

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $names = $this->projectNames($sheet);
        $types = [
            (string) $sheet->getCell('E'.$this->projectRow($sheet, 'herstel'))->getValue(),
            (string) $sheet->getCell('E'.$this->projectRow($sheet, 'Gezondheidscentrum Laren'))->getValue(),
            (string) $sheet->getCell('E'.$this->projectRow($sheet, 'plinten vervangen'))->getValue(),
        ];

        $this->assertContains('herstel', $names);
        $this->assertContains('Gezondheidscentrum Laren', $names);
        $this->assertContains('plinten vervangen', $names);
        $this->assertTrue(collect($types)->contains(fn (string $type): bool => str_contains($type, 'SERVICE')));
        $this->assertTrue(collect($types)->contains(fn (string $type): bool => str_contains($type, 'KLEIN')));
        $this->assertSame(4, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Nick', 37, 1));
        $this->assertSame(8, $this->hoursAt($sheet, 'Gezondheidscentrum Laren', 'Nick', 37, 2));
    }

    public function test_shows_nicon_logo_and_marks_winkel_versus_projecten(): void
    {
        $user = User::factory()->create();
        $owner = Customer::query()->create(['name' => 'Harm Wesselink']);
        [$worker, $winkel, $winkelItem] = $this->makeProjectWorker('Harm', 'Harm Wesselink - Zwolle', [
            'kind' => ProjectKind::Winkel,
            'customer_id' => $owner->id,
            'project_number' => '2026-003',
            'city' => 'Zwolle',
        ]);
        [$project, $item] = $this->makeProject('Gezondheidscentrum Laren', [
            'project_number' => '260200091',
            'city' => 'Laren',
        ]);
        $this->assign($worker, $winkel, $winkelItem, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($worker, $project, $item, '2026-09-08', '2026-09-08', '08:00:00', '16:00:00');

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $drawings = $sheet->getDrawingCollection();
        $this->assertCount(1, $drawings);
        $this->assertSame('A1', $drawings[0]->getCoordinates());
        $this->assertSame('Nicon Vloeren', $drawings[0]->getName());

        $winkelRow = $this->projectRow($sheet, 'Harm Wesselink - Zwolle');
        $projectRow = $this->projectRow($sheet, 'Gezondheidscentrum Laren');
        $this->assertSame('Kloppenburg Interieur', $sheet->getCell('A'.$winkelRow)->getValue());
        $this->assertSame('Nicon Vloeren', $sheet->getCell('A'.$projectRow)->getValue());
        $this->assertSame('Kloppenburg Interieur', $sheet->getCell('A'.$this->rowInProject($sheet, 'Harm Wesselink - Zwolle', 'Harm'))->getValue());
        $this->assertSame('Nicon Vloeren', $sheet->getCell('A'.$this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Harm'))->getValue());
    }

    public function test_work_total_compares_calculation_budget_with_planned_hours(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Wesselink Media',
            'company' => 'Wesselink Media',
            'employment_type' => 'zzp',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => ''],
                ['name' => 'Harm Wesselink', 'phone' => ''],
            ],
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject('Gezondheidscentrum Laren', [
            'city' => 'Laren',
        ]);
        $item->forceFill(['begrote_uren' => 100.3])->save();
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Extra plinten',
            'unit' => 'm1',
            'ordered_quantity' => 20,
            'begrote_uren' => 50,
            'is_extra_work' => true,
            'small_work_type' => SmallWorkType::Extra,
            'status' => 'gepland',
        ]);
        $people = $worker->crewPeople()->orderBy('sort_order')->get();
        $eric = $people->firstWhere('name', 'Eric Wesselink');
        $harm = $people->firstWhere('name', 'Harm Wesselink');
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-14', '08:00:00', '16:00:00', [$eric->id]);
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-15', '08:00:00', '16:00:00', [$harm->id]);

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $ericRow = $this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Wesselink Media (Eric W.)');
        $harmRow = $this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Wesselink Media (Harm W.)');
        $totalRow = $this->rowInProject($sheet, 'Gezondheidscentrum Laren', 'Totaal werk');

        $this->assertSame('—', $sheet->getCell('H'.$ericRow)->getValue());
        $this->assertSame('—', $sheet->getCell('J'.$ericRow)->getValue());
        $this->assertHoursFormula($sheet, 'I'.$ericRow, 48);
        $this->assertSame('—', $sheet->getCell('H'.$harmRow)->getValue());
        $this->assertHoursFormula($sheet, 'I'.$harmRow, 56);
        $this->assertSame(150.3, $this->numericHours($sheet->getCell('H'.$totalRow)));
        $this->assertHoursFormula($sheet, 'I'.$totalRow, 104);
        $this->assertHoursFormula($sheet, 'J'.$totalRow, -46.3);
        $this->assertSame('548235', $sheet->getStyle('J'.$totalRow)->getFont()->getColor()->getRGB());
    }

    public function test_work_total_difference_turns_red_when_planned_hours_exceed_budget(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Albert', 'Laakse Tuinen');
        $item->forceFill(['begrote_uren' => 8])->save();
        $this->assign($worker, $project, $item, '2026-09-07', '2026-09-08', '08:00:00', '16:00:00');

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $totalRow = $this->rowInProject($sheet, 'Laakse Tuinen', 'Totaal werk');
        $this->assertSame(8, $this->numericHours($sheet->getCell('H'.$totalRow)));
        $this->assertHoursFormula($sheet, 'I'.$totalRow, 16);
        $this->assertHoursFormula($sheet, 'J'.$totalRow, 8);
        $this->assertSame('C00000', $sheet->getStyle('J'.$totalRow)->getFont()->getColor()->getRGB());
    }

    public function test_export_spreads_every_iso_week_of_the_selected_year(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Albert', 'Laakse Tuinen');
        $this->assign($worker, $project, $item, '2026-01-02', '2026-01-02', '08:00:00', '16:00:00');
        $this->assign($worker, $project, $item, '2026-12-28', '2026-12-28', '08:00:00', '12:00:00');
        [$other, $otherItem] = $this->makeProject('School Zwolle', [
            'project_number' => '260200091',
            'city' => 'Zwolle',
        ]);
        $this->assign($worker, $other, $otherItem, '2025-12-22', '2025-12-22', '08:00:00', '16:00:00');

        $response = $this->actingAs($user)
            ->get(route('planning.excel', ['week' => '2026-09-07', 'year' => 2026]));

        $response->assertDownload('Nicon-planning-2026.xlsx');
        $sheet = $this->sheetFrom($response);

        $this->assertSame(1, (int) $sheet->getCell('K1')->getValue());
        $this->assertSame(53, (int) $sheet->getCell($this->dayColumn(53, 0).'1')->getValue());
        $this->assertSame(8, $this->hoursAt($sheet, 'Laakse Tuinen', 'Albert', 1, 4));
        $this->assertSame(4, $this->hoursAt($sheet, 'Laakse Tuinen', 'Albert', 53, 0));
        $this->assertSame(['Laakse Tuinen', 'School Zwolle'], $this->projectNames($sheet));
        $this->assertSame(0, $this->hoursAt($sheet, 'School Zwolle', 'Totaal werk', 1, 0));
    }

    public function test_moved_assignment_changes_the_next_export(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $item] = $this->makeProjectWorker('Albert', 'Laakse Tuinen');
        $assignment = $this->assign($worker, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');

        $before = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );
        $this->assertSame(8, $this->hoursAt($before, 'Laakse Tuinen', 'Albert', 37, 0));
        $this->assertNull($this->hoursAt($before, 'Laakse Tuinen', 'Albert', 37, 1));

        $assignment->applySchedule(
            Carbon::parse('2026-09-08'),
            Carbon::parse('2026-09-08'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();

        $after = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );
        $this->assertNull($this->hoursAt($after, 'Laakse Tuinen', 'Albert', 37, 0));
        $this->assertSame(8, $this->hoursAt($after, 'Laakse Tuinen', 'Albert', 37, 1));
    }

    public function test_export_includes_a_work_without_vakmen_or_werkzaamheden(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Gemeente Kampen']);
        Project::query()->create([
            'project_number' => '260200093',
            'customer_id' => $customer->id,
            'name' => 'Kindcentrum IJssel',
            'city' => 'Kampen',
            'status' => 'gepland',
            'planned_start_date' => '2026-10-05',
            'planned_end_date' => '2026-10-16',
        ]);

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $row = $this->projectRow($sheet, 'Kindcentrum IJssel');
        $this->assertSame(['Kindcentrum IJssel'], $this->projectNames($sheet));
        $this->assertSame('Gemeente Kampen', $sheet->getCell('B'.$row)->getValue());
        $this->assertSame('Kampen', $sheet->getCell('D'.$row)->getValue());
        $this->assertSame('', (string) $sheet->getCell('E'.$row)->getValue());
        $this->assertSame('', (string) $sheet->getCell('F'.$row)->getValue());
        $this->assertSame('', (string) $sheet->getCell('G'.$row)->getValue());
        $this->assertSame('—', $sheet->getCell('H'.$this->rowInProject($sheet, 'Kindcentrum IJssel', 'Totaal werk'))->getValue());
        $this->assertHoursFormula($sheet, $this->totalHoursCell($sheet, 'Kindcentrum IJssel', 'Totaal werk'), 0);
    }

    public function test_limited_access_user_does_not_see_another_project(): void
    {
        [$nick, $own, $item] = $this->makeProjectWorker('Nick', 'Laakse Tuinen', [
            'project_number' => '260200090',
        ]);
        [$other, $otherItem] = $this->makeProject('Kindcentrum Veldhoeve', [
            'project_number' => '260200091',
            'city' => 'Zwolle',
        ]);
        $otherWorker = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $this->assign($nick, $own, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($otherWorker, $other, $otherItem, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($own);

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame(['Laakse Tuinen'], $this->projectNames($sheet));
    }

    public function test_vakman_export_contains_only_his_scheduled_project(): void
    {
        [$nick, $own, $item] = $this->makeProjectWorker('Nick Seine', 'Laakse Tuinen', [
            'project_number' => '260200090',
        ]);
        [$other, $otherItem] = $this->makeProject('Kindcentrum Veldhoeve', [
            'project_number' => '260200091',
        ]);
        $kees = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $this->assign($nick, $own, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($kees, $other, $otherItem, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $user = User::factory()->vakman($nick->id)->create();

        $sheet = $this->sheetFrom(
            $this->actingAs($user)->get(route('planning.excel', ['year' => 2026]))
        );

        $this->assertSame(['Laakse Tuinen'], $this->projectNames($sheet));
        $this->assertSame(8, $this->hoursAt($sheet, 'Laakse Tuinen', 'Nick Seine', 37, 0));
    }

    private function seedProjectWithHours(): void
    {
        $albert = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'color' => '#c2410c',
            'active' => true,
        ]);
        $nick = Worker::query()->create([
            'name' => 'Nick',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 3835,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
            'status' => 'in_uitvoering',
        ]);
        $this->assign($albert, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($nick, $project, $item, '2026-09-07', '2026-09-07', '08:00:00', '16:00:00');
        $this->assign($albert, $project, $item, '2026-09-08', '2026-09-08', '08:00:00', '12:00:00');

        $otherCustomer = Customer::query()->create(['name' => 'Schoolbestuur']);
        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $otherCustomer->id,
            'name' => 'School Zwolle',
            'city' => 'Zwolle',
            'status' => 'gepland',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
        ]);
        WorkItem::query()->create([
            'project_id' => $other->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'status' => 'gepland',
        ]);
    }

    /**
     * @param  array<string, mixed>  $projectAttributes
     * @return array{0: Worker, 1: Project, 2: WorkItem}
     */
    private function makeProjectWorker(string $workerName, string $projectName, array $projectAttributes = []): array
    {
        $worker = Worker::query()->create([
            'name' => $workerName,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        [$project, $item] = $this->makeProject($projectName, $projectAttributes);

        return [$worker, $project, $item];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Project, 1: WorkItem}
     */
    private function makeProject(string $name, array $attributes = []): array
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create(array_merge([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ], $attributes));
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => $project->planned_start_date,
            'planned_end_date' => $project->planned_end_date,
            'status' => 'in_uitvoering',
        ]);

        return [$project, $item];
    }

    /**
     * @param  list<int>  $crewMemberIds
     */
    private function assign(
        Worker $worker,
        Project $project,
        WorkItem $item,
        string $startDate,
        string $endDate,
        string $startTime,
        string $endTime,
        array $crewMemberIds = [],
    ): WorkerAssignment {
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'hours_per_day' => 8,
            'planned_hours' => 8,
        ]);
        $assignment->applySchedule(
            $assignment->start_date,
            $assignment->end_date,
            $startTime,
            $endTime,
        );
        $assignment->save();
        if ($crewMemberIds !== []) {
            $assignment->syncPresentCrew($crewMemberIds);
        }

        return $assignment->fresh(['crewMembers']);
    }

    private function sheetFrom(mixed $response): Worksheet
    {
        $path = $response->getFile()->getPathname();
        $this->assertFileExists($path);

        return IOFactory::load($path)->getActiveSheet();
    }

    private function dayColumn(int $isoWeek, int $weekdayIndex): string
    {
        return Coordinate::stringFromColumnIndex(11 + (($isoWeek - 1) * 6) + $weekdayIndex);
    }

    private function weekTotalColumn(int $isoWeek): string
    {
        return Coordinate::stringFromColumnIndex(11 + (($isoWeek - 1) * 6) + 5);
    }

    private function totalHoursCell(Worksheet $sheet, string $project, string $label): string
    {
        return 'I'.$this->rowInProject($sheet, $project, $label);
    }

    private function projectRow(Worksheet $sheet, string $name): int
    {
        for ($row = 3; $row <= $sheet->getHighestRow(); $row++) {
            if ((string) $sheet->getCell('C'.$row)->getValue() === $name) {
                return $row;
            }
        }

        $this->fail('Project '.$name.' ontbreekt in de Excel.');
    }

    /**
     * @return list<string>
     */
    private function projectNames(Worksheet $sheet): array
    {
        $names = [];
        for ($row = 3; $row <= $sheet->getHighestRow(); $row++) {
            $name = (string) $sheet->getCell('C'.$row)->getValue();
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function labelsInProject(Worksheet $sheet, string $project): array
    {
        $start = $this->projectRow($sheet, $project);
        $labels = [];
        for ($row = $start; $row <= $sheet->getHighestRow(); $row++) {
            if ($row > $start && (string) $sheet->getCell('C'.$row)->getValue() !== '') {
                break;
            }
            $label = (string) $sheet->getCell('G'.$row)->getValue();
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function rowInProject(Worksheet $sheet, string $project, string $label): int
    {
        $start = $this->projectRow($sheet, $project);
        for ($row = $start; $row <= $sheet->getHighestRow(); $row++) {
            if ($row > $start && (string) $sheet->getCell('C'.$row)->getValue() !== '') {
                break;
            }
            if (trim((string) $sheet->getCell('G'.$row)->getValue()) === $label) {
                return $row;
            }
        }

        $this->fail('Rij '.$label.' ontbreekt bij '.$project.'.');
    }

    private function hoursAt(Worksheet $sheet, string $project, string $person, int $week, int $day): mixed
    {
        $row = $this->rowInProject($sheet, $project, $person);

        return $this->numericHours($sheet->getCell($this->dayColumn($week, $day).$row));
    }

    private function assertHoursFormula(Worksheet $sheet, string $coordinate, int|float $hours): void
    {
        $cell = $sheet->getCell($coordinate);
        $this->assertTrue(str_starts_with((string) $cell->getValue(), '='), 'Cel '.$coordinate.' mist een Excel-formule.');
        $this->assertSame($hours, $this->numericHours($cell));
    }

    private function numericHours(Cell $cell): mixed
    {
        $value = $cell->getValue();
        if (is_string($value) && str_starts_with($value, '=')) {
            $value = $cell->getCalculatedValue();
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return $value;
        }

        $number = (float) $value;
        if (abs($number - round($number)) < 0.001) {
            return (int) round($number);
        }

        return round($number, 1);
    }
}
