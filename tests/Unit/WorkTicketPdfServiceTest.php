<?php

namespace Tests\Unit;

use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Services\WorkTicketPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkTicketPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_filename_uses_kind_company_and_number(): void
    {
        $ticket = $this->makeTicket();

        $filename = app(WorkTicketPdfService::class)->filename($ticket);

        $this->assertSame('Opdrachtbon_Het-Vloerenhuis_250100010_OB-2026-0001.pdf', $filename);
    }

    public function test_build_omits_prices_when_show_prices_is_false(): void
    {
        $ticket = $this->makeTicket();

        $data = app(WorkTicketPdfService::class)->build($ticket, false);

        $this->assertSame('OPDRACHTBON', $data['documentTitle']);
        $this->assertFalse($data['showPrices']);
        $this->assertSame('Het Vloerenhuis', $data['recipient']);
        $this->assertSame('Gezondheidscentrum Laren', $data['projectTitle']);
    }

    public function test_build_keeps_prices_for_the_zzp_opdrachtbon(): void
    {
        $ticket = $this->makeTicket();

        $data = app(WorkTicketPdfService::class)->build($ticket, true);

        $this->assertTrue($data['showPrices']);
        $this->assertSame(1050.0, (float) $ticket->lines->first()->amount);
    }

    private function makeTicket(): WorkTicket
    {
        $worker = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'zzp',
            'company' => 'Het Vloerenhuis',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gezondheidscentrum Laren']);
        $project = Project::query()->create([
            'project_number' => '250100010',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'city' => 'Laren',
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 84,
            'status' => 'gepland',
        ]);
        $ticket = WorkTicket::query()->create([
            'number' => 'OB-2026-0001',
            'kind' => WorkTicketKind::Opdrachtbon,
            'worker_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'billing_method' => WorkTicketBilling::Unit,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
        ]);
        $ticket->lines()->create([
            'work_item_id' => $item->id,
            'quantity' => 84,
            'unit' => WorkUnit::SquareMeter,
            'unit_price' => 12.5,
            'amount' => 1050,
        ]);

        return $ticket->fresh(['worker', 'project', 'lines.workItem']);
    }
}
