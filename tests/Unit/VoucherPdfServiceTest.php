<?php

namespace Tests\Unit;

use App\Enums\VoucherType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\Voucher;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Services\VoucherPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_filename_uses_company_project_and_work_numbers(): void
    {
        $voucher = $this->makeOpdracht();

        $filename = app(VoucherPdfService::class)->filename($voucher);

        $this->assertSame('Opdrachtbon_Wesselink-Media_11P241267_250100010.pdf', $filename);
    }

    public function test_build_recalculates_the_activity_total_from_quantities_and_price(): void
    {
        $voucher = $this->makeOpdracht(['total_amount' => 1]);

        $data = app(VoucherPdfService::class)->build($voucher);

        $this->assertSame('Wesselink Media', $data['recipient']);
        $this->assertSame('Gezondheidscentrum Laren', $data['projectTitle']);
        $this->assertSame('11P241267', $data['projectNumber']);
        $this->assertSame('250100010', $data['workNumber']);
        $this->assertSame('OPDRACHTBON', $data['documentTitle']);
        $this->assertSame('Nicon Vloeren', $data['companyName']);
        $this->assertSame('Manenbergring 9', $data['companyAddress']);
        $this->assertCount(1, $data['groups']);
        $this->assertSame(143.01, $data['groups'][0]['quantity']);
        $this->assertSame(286.02, $data['groups'][0]['amount']);
        $this->assertSame(286.02, $data['total']);
        $this->assertCount(4, $data['groups'][0]['entries']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOpdracht(array $overrides = []): Voucher
    {
        $worker = Worker::query()->create([
            'name' => 'Wepro',
            'employment_type' => 'zzp',
            'company' => 'Wesselink Media',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gezondheidscentrum Laren']);
        $project = Project::query()->create([
            'project_number' => '250100010',
            'customer_id' => $customer->id,
            'name' => '11P241267 Gezondheidscentrum Laren',
            'city' => 'Laren',
            'status' => 'in_uitvoering',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => '1e verdieping',
            'sort_order' => 1,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 143.01,
            'status' => 'in_uitvoering',
            'sort_order' => 1,
        ]);
        $voucher = Voucher::query()->create(array_merge([
            'number' => 'BON-2026-0001',
            'type' => VoucherType::Opdracht,
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'issued_on' => '2026-09-12',
            'total_amount' => 286.02,
        ], $overrides));

        $rooms = [
            ['1.62', 'oefenruimte', 83.65],
            ['1.64', 'cabine', 16.31],
            ['1.65', 'cabine 3', 16.33],
            ['1.67', 'behandelkamer groot/kracht', 26.72],
        ];
        foreach ($rooms as $room) {
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $room[0],
                'name' => $room[1],
                'square_meters' => $room[2],
                'status' => 'in_uitvoering',
            ]);
            $voucher->lines()->create([
                'project_area_id' => $area->id,
                'work_item_id' => $item->id,
                'description' => $room[0].' '.$room[1].' · Primen & Egaliseren',
                'quantity' => $room[2],
                'unit' => 'm2',
                'unit_price' => 2,
                'amount' => 0,
            ]);
        }

        return $voucher->fresh(['worker', 'project', 'lines.area']);
    }
}
