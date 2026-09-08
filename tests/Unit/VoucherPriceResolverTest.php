<?php

namespace Tests\Unit;

use App\Enums\VoucherPriceSource;
use App\Enums\VoucherType;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Voucher;
use App\Models\Worker;
use App\Models\WorkerRate;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use App\Services\VoucherPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherPriceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_the_worker_rate_when_nothing_else_is_set(): void
    {
        [$worker, $project, $item] = $this->makeWork();
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'primen_egaliseren',
            'unit' => 'm2',
            'unit_price' => 4.50,
        ]);
        $worker->load('rates');

        $resolved = app(VoucherPriceResolver::class)->resolve(
            $worker,
            $item,
            WorkUnit::SquareMeter,
            null,
            collect(),
        );

        $this->assertSame(4.5, $resolved['price']);
        $this->assertSame(VoucherPriceSource::Rate, $resolved['source']);
    }

    public function test_prefers_the_work_order_price_over_the_worker_rate(): void
    {
        [$worker, $project, $item] = $this->makeWork();
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'primen_egaliseren',
            'unit' => 'm2',
            'unit_price' => 4.50,
        ]);
        $order = WorkOrder::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_id' => $worker->id,
            'assignment_type' => 'work_item',
            'unit' => 'm2',
            'unit_price' => 7.25,
            'status' => 'gepland',
        ]);
        $worker->load('rates');

        $resolved = app(VoucherPriceResolver::class)->resolve(
            $worker,
            $item,
            WorkUnit::SquareMeter,
            null,
            collect([$order]),
        );

        $this->assertSame(7.25, $resolved['price']);
        $this->assertSame(VoucherPriceSource::Order, $resolved['source']);
    }

    public function test_prefers_the_opdrachtbon_over_the_work_order(): void
    {
        [$worker, $project, $item] = $this->makeWork();
        $order = WorkOrder::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_id' => $worker->id,
            'assignment_type' => 'work_item',
            'unit' => 'm2',
            'unit_price' => 7.25,
            'status' => 'gepland',
        ]);
        $opdracht = Voucher::query()->create([
            'number' => 'BON-2026-0001',
            'type' => VoucherType::Opdracht,
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'issued_on' => '2026-09-02',
            'total_amount' => 50.25,
        ]);
        $opdracht->lines()->create([
            'work_item_id' => $item->id,
            'specialty_key' => 'primen_egaliseren',
            'description' => 'Primen & Egaliseren',
            'quantity' => 50.25,
            'unit' => 'm2',
            'unit_price' => 6.00,
            'amount' => 301.50,
            'price_source' => VoucherPriceSource::Manual,
        ]);
        $opdracht->load('lines');
        $worker->load('rates');

        $resolved = app(VoucherPriceResolver::class)->resolve(
            $worker,
            $item,
            WorkUnit::SquareMeter,
            $opdracht,
            collect([$order]),
        );

        $this->assertSame(6.0, $resolved['price']);
        $this->assertSame(VoucherPriceSource::Voucher, $resolved['source']);
    }

    public function test_falls_back_to_manual_when_no_price_is_known(): void
    {
        [$worker, $project, $item] = $this->makeWork();
        $worker->load('rates');

        $resolved = app(VoucherPriceResolver::class)->resolve(
            $worker,
            $item,
            WorkUnit::SquareMeter,
            null,
            collect(),
        );

        $this->assertNull($resolved['price']);
        $this->assertSame(VoucherPriceSource::Manual, $resolved['source']);
    }

    public function test_uses_the_hourly_rate_when_the_unit_is_hours(): void
    {
        [$worker, $project, $item] = $this->makeWork();
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => WorkerRate::HOURLY_SPECIALTY,
            'unit' => WorkUnit::Hours,
            'unit_price' => 45.00,
        ]);
        $worker->load('rates');

        $resolved = app(VoucherPriceResolver::class)->resolve(
            $worker,
            $item,
            WorkUnit::Hours,
            null,
            collect(),
        );

        $this->assertSame(45.0, $resolved['price']);
        $this->assertSame(VoucherPriceSource::Rate, $resolved['source']);
    }

    /** @return array{0: Worker, 1: Project, 2: WorkItem} */
    private function makeWork(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 50.25,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);

        return [$worker, $project, $item];
    }
}
