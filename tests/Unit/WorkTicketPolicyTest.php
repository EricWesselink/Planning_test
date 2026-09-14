<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkTicketPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('createRoles')]
    public function test_create_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('create', WorkTicket::class));
    }

    public function test_eigen_werkbon_hides_prices_from_everyone(): void
    {
        $ticket = $this->makeTicket(WorkTicketKind::Werkbon);
        $planner = User::factory()->make(['role' => UserRole::Planner]);
        $vakman = User::factory()->vakman($ticket->worker_id)->create();

        $this->assertFalse($planner->can('viewPrices', $ticket));
        $this->assertFalse($vakman->can('viewPrices', $ticket));
    }

    public function test_zzp_sees_prices_on_own_opdrachtbon_only(): void
    {
        $ticket = $this->makeTicket(WorkTicketKind::Opdrachtbon);
        $planner = User::factory()->make(['role' => UserRole::Planner]);
        $owner = User::factory()->vakman($ticket->worker_id)->create();
        $otherWorker = Worker::query()->create([
            'name' => 'Andere ploeg',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $other = User::factory()->vakman($otherWorker->id)->create();

        $this->assertTrue($planner->can('viewPrices', $ticket));
        $this->assertTrue($owner->can('viewPrices', $ticket));
        $this->assertFalse($other->can('viewPrices', $ticket));
        $this->assertFalse($other->can('view', $ticket));
    }

    public function test_uitvoerder_cannot_see_zzp_prices(): void
    {
        $ticket = $this->makeTicket(WorkTicketKind::Opdrachtbon);
        $user = User::factory()->uitvoerder()->make();

        $this->assertTrue($user->can('view', $ticket));
        $this->assertFalse($user->can('viewPrices', $ticket));
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function createRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'planner' => [UserRole::Planner, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'vakman' => [UserRole::Vakman, false],
        ];
    }

    private function makeTicket(WorkTicketKind $kind): WorkTicket
    {
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => $kind === WorkTicketKind::Opdrachtbon ? 'zzp' : 'eigen',
            'company' => $kind === WorkTicketKind::Opdrachtbon ? 'Het Vloerenhuis' : null,
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Laren']);
        $project = Project::query()->create([
            'project_number' => 'P-1',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'status' => 'gepland',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);

        return WorkTicket::query()->create([
            'number' => $kind === WorkTicketKind::Opdrachtbon ? 'OB-2026-0001' : 'WB-2026-0001',
            'kind' => $kind,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'billing_method' => $kind === WorkTicketKind::Opdrachtbon ? WorkTicketBilling::Unit : null,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);
    }
}
