<?php

namespace Database\Seeders;

use App\Enums\AreaStatus;
use App\Enums\EmploymentType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\WorkOrderType;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\ProjectNote;
use App\Models\Team;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use App\Models\WorkProgressEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $planner = User::query()->create([
            'name' => 'Nicon Planner',
            'email' => 'planner@niconvloeren.nl',
            'password' => Hash::make('password'),
            'role' => UserRole::Planner,
            'active' => true,
            'email_verified_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'customer_number' => 'K-1042',
            'name' => 'TMZ Meubelenbelt',
            'contact_name' => 'Marco Visscher',
            'phone' => '038 123 45 67',
            'city' => 'Zwolle',
        ]);

        $project = Project::query()->create([
            'project_number' => '2024-118',
            'customer_id' => $customer->id,
            'name' => 'TMZ Meubelenbelt Fase 2',
            'address' => 'Meubelenbelt 2',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
            'supervisor_user_id' => $planner->id,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-25',
            'status' => ProjectStatus::InUitvoering,
        ]);

        $upcoming = Project::query()->create([
            'project_number' => '2024-121',
            'customer_id' => $customer->id,
            'name' => 'Apotheek Zwolle',
            'city' => 'Zwolle',
            'supervisor_user_id' => $planner->id,
            'planned_start_date' => '2026-09-21',
            'planned_end_date' => '2026-10-02',
            'status' => ProjectStatus::Gepland,
        ]);

        WorkItem::query()->create([
            'project_id' => $upcoming->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 1500,
            'planned_start_date' => '2026-09-21',
            'planned_end_date' => '2026-09-25',
            'status' => 'gepland',
            'sort_order' => 1,
        ]);

        $bg = ProjectFloor::query()->create(['project_id' => $project->id, 'name' => 'Begane grond', 'sort_order' => 1]);
        $showroom = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $bg->id,
            'area_number' => '01',
            'name' => 'Showroom',
            'square_meters' => 860,
            'sort_order' => 1,
        ]);
        $magazijn = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $bg->id,
            'area_number' => '02',
            'name' => 'Magazijn',
            'square_meters' => 420,
            'sort_order' => 2,
        ]);
        $kantoor = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $bg->id,
            'area_number' => '03',
            'name' => 'Kantoor',
            'square_meters' => 48,
            'sort_order' => 3,
        ]);

        $items = [
            ['Egaliseren', WorkUnit::SquareMeter, 4323, '2026-09-08', '2026-09-12', 'in_uitvoering', 1],
            ['PVC', WorkUnit::SquareMeter, 3800, '2026-09-10', '2026-09-18', 'in_uitvoering', 2],
            ['Plinten', WorkUnit::LinearMeter, 180, '2026-09-22', '2026-09-25', 'gepland', 3],
        ];

        $work = [];
        foreach ($items as $row) {
            $work[$row[0]] = WorkItem::query()->create([
                'project_id' => $project->id,
                'name' => $row[0],
                'unit' => $row[1],
                'ordered_quantity' => $row[2],
                'planned_start_date' => $row[3],
                'planned_end_date' => $row[4],
                'status' => $row[5],
                'sort_order' => $row[6],
            ]);
        }

        foreach (
            [
                [$showroom, 860, 860],
                [$magazijn, 420, 420],
                [$kantoor, 48, 48],
            ] as $row
        ) {
            AreaTask::query()->create([
                'project_area_id' => $row[0]->id,
                'work_item_id' => $work['Egaliseren']->id,
                'ordered_quantity' => $row[1],
                'unit' => WorkUnit::SquareMeter,
                'status' => AreaStatus::NietGestart,
            ]);
            AreaTask::query()->create([
                'project_area_id' => $row[0]->id,
                'work_item_id' => $work['PVC']->id,
                'ordered_quantity' => $row[2],
                'unit' => WorkUnit::SquareMeter,
                'status' => AreaStatus::NietGestart,
            ]);
        }

        $albert = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => EmploymentType::Eigen,
            'specialty' => 'dekvloeren',
            'phone' => '06 11111111',
            'email' => 'albert@niconvloeren.nl',
            'color' => '#c2410c',
            'address' => 'Industrieweg 8',
            'postal_code' => '8013 PM',
            'city' => 'Zwolle',
            'default_hours_per_day' => 8,
        ]);
        $jan = Worker::query()->create([
            'name' => 'Jan',
            'employment_type' => EmploymentType::Eigen,
            'specialty' => 'dekvloeren',
            'phone' => '06 22222222',
            'email' => 'jan@niconvloeren.nl',
            'color' => '#1d4ed8',
            'city' => 'Zwolle',
        ]);
        $peter = Worker::query()->create([
            'name' => 'Peter',
            'employment_type' => EmploymentType::Eigen,
            'specialty' => 'vinyl',
            'phone' => '06 33333333',
            'email' => 'peter@niconvloeren.nl',
            'color' => '#15803d',
            'city' => 'Zwolle',
        ]);
        $jansen = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => EmploymentType::Zzp,
            'company' => 'Jansen Vloeren',
            'contact_name' => 'Kees Jansen',
            'phone' => '06 44444444',
            'email' => 'kees@jansen-vloeren.nl',
            'color' => '#7c3aed',
            'address' => 'Molenstraat 14',
            'postal_code' => '8011 AB',
            'city' => 'Zwolle',
            'specialty' => 'PVC',
            'people_count' => 3,
            'crew_names' => 'Piet, Kees, Jan',
            'crew_members' => [
                ['name' => 'Piet', 'phone' => '06 44444444'],
                ['name' => 'Kees', 'phone' => ''],
                ['name' => 'Jan', 'phone' => ''],
            ],
        ]);

        $ploeg = Team::query()->create(['name' => 'Ploeg 1', 'active' => true]);
        $ploeg->workers()->attach([
            $albert->id => ['valid_from' => '2026-01-01'],
            $jan->id => ['valid_from' => '2026-01-01'],
        ]);

        WorkerAssignment::query()->create([
            'worker_id' => $albert->id,
            'project_id' => $project->id,
            'work_item_id' => $work['Egaliseren']->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
            'team_id' => $ploeg->id,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $jan->id,
            'project_id' => $project->id,
            'work_item_id' => $work['Egaliseren']->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-10',
            'hours_per_day' => 8,
            'team_id' => $ploeg->id,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $peter->id,
            'project_id' => $project->id,
            'work_item_id' => $work['PVC']->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $jansen->id,
            'project_id' => $project->id,
            'work_item_id' => $work['PVC']->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
            'people_count' => 2,
        ]);

        WorkOrder::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $work['Egaliseren']->id,
            'worker_id' => $albert->id,
            'assignment_type' => WorkOrderType::WorkItem,
            'unit' => WorkUnit::SquareMeter,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        WorkOrder::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $work['PVC']->id,
            'worker_id' => $jansen->id,
            'assignment_type' => WorkOrderType::Partial,
            'assigned_quantity' => 800,
            'unit' => WorkUnit::SquareMeter,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-18',
            'status' => 'in_uitvoering',
            'notes' => '800 van 3.800 m² PVC',
        ]);
        WorkOrder::query()->create([
            'project_id' => $upcoming->id,
            'worker_id' => $jansen->id,
            'assignment_type' => WorkOrderType::Project,
            'start_date' => '2026-09-21',
            'end_date' => '2026-10-02',
            'status' => 'gepland',
            'notes' => 'Gehele vloerwerk Apotheek Zwolle',
        ]);

        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $work['Egaliseren']->id,
            'worker_id' => $albert->id,
            'date' => '2026-09-08',
            'completed_quantity' => 1100,
            'unit' => WorkUnit::SquareMeter,
            'worked_hours' => 16,
            'created_by' => $planner->id,
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $work['Egaliseren']->id,
            'worker_id' => $jan->id,
            'date' => '2026-09-09',
            'completed_quantity' => 1000,
            'unit' => WorkUnit::SquareMeter,
            'worked_hours' => 16,
            'created_by' => $planner->id,
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $work['PVC']->id,
            'worker_id' => $jansen->id,
            'date' => '2026-09-10',
            'completed_quantity' => 285,
            'unit' => WorkUnit::SquareMeter,
            'worked_hours' => 8,
            'note' => 'ZZP Jansen PVC',
            'created_by' => $planner->id,
        ]);
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $work['PVC']->id,
            'worker_id' => $peter->id,
            'date' => '2026-09-10',
            'completed_quantity' => 565,
            'unit' => WorkUnit::SquareMeter,
            'worked_hours' => 8,
            'created_by' => $planner->id,
        ]);

        ProjectNote::query()->create([
            'project_id' => $project->id,
            'user_id' => $planner->id,
            'note' => 'ZZP Jansen doet 800 m² PVC. Rest in eigen ploeg.',
        ]);
    }
}
