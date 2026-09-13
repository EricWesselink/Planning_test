<?php

namespace Tests\Feature;

use App\Enums\VoucherType;
use App\Mail\WorkerVoucherMail;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\Voucher;
use App\Models\Worker;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerVoucherMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_tells_the_worker_to_attach_the_bon_to_the_invoice(): void
    {
        $voucher = $this->makeBon();

        $mail = new WorkerVoucherMail($voucher);

        $this->assertSame('Bon BON-2026-0002 · Laakse Tuinen Amersfoort', $mail->envelope()->subject);
        $mail->assertSeeInHtml('Voeg deze bon bij je factuur.');
        $mail->assertSeeInHtml('ZZP Harm Wesselink');
        $mail->assertSeeInHtml('Primen & Egaliseren');
        $mail->assertSeeInHtml('30,00');
        $mail->assertSeeInHtml('165,00');
        $mail->assertSeeInText('Voeg deze bon bij je factuur.');
        $mail->assertSeeInText('Totaal te factureren: € 165,00');
    }

    public function test_html_lists_rooms_under_the_activity_without_per_room_prices(): void
    {
        $voucher = $this->makeBon();
        $item = WorkItem::query()->create([
            'project_id' => $voucher->project_id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 101.41,
            'status' => 'in_uitvoering',
            'sort_order' => 1,
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $voucher->project_id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $first = ProjectArea::query()->create([
            'project_id' => $voucher->project_id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.02',
            'name' => 'groepsruimte',
            'square_meters' => 50.25,
            'status' => 'in_uitvoering',
        ]);
        $second = ProjectArea::query()->create([
            'project_id' => $voucher->project_id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.03',
            'name' => 'groepsruimte',
            'square_meters' => 51.16,
            'status' => 'in_uitvoering',
        ]);
        $voucher->lines()->delete();
        $voucher->lines()->create([
            'project_area_id' => $first->id,
            'work_item_id' => $item->id,
            'description' => '0.02 groepsruimte · Primen & Egaliseren',
            'quantity' => 50.25,
            'unit' => 'm2',
            'unit_price' => 2,
            'amount' => 100.50,
        ]);
        $voucher->lines()->create([
            'project_area_id' => $second->id,
            'work_item_id' => $item->id,
            'description' => '0.03 groepsruimte · Primen & Egaliseren',
            'quantity' => 51.16,
            'unit' => 'm2',
            'unit_price' => 2,
            'amount' => 102.32,
        ]);
        $voucher->forceFill(['total_amount' => 202.82])->save();
        $voucher = $voucher->fresh(['worker', 'project', 'lines.area']);

        $mail = new WorkerVoucherMail($voucher);

        $mail->assertSeeInHtml('Primen & Egaliseren');
        $mail->assertSeeInHtml('101,41 m²');
        $mail->assertSeeInHtml('€ 2,00/m²');
        $mail->assertSeeInHtml('€ 202,82');
        $mail->assertSeeInHtml('0.02 groepsruimte');
        $mail->assertSeeInHtml('0.03 groepsruimte');
        $mail->assertSeeInText('0.02 groepsruimte: 50,25 m²');
        $mail->assertSeeInText('0.03 groepsruimte: 51,16 m²');
        $mail->assertDontSeeInHtml('€ 100,50');
    }

    public function test_escapes_dangerous_content_in_the_mail(): void
    {
        $voucher = $this->makeBon([
            'company' => '<script>alert("xss")</script>',
            'description' => 'Kim <script>alert("xss")</script>',
            'notes' => '<script>alert("xss")</script>',
            'project' => 'Project <script>alert("xss")</script>',
        ]);

        $mail = new WorkerVoucherMail($voucher);

        $mail->assertDontSeeInHtml('<script>alert("xss")</script>', false);
        $mail->assertSeeInHtml('<script>alert("xss")</script>');
        $mail->assertDontSeeInText('<script>alert("xss")</script>');
    }

    /**
     * @param  array{company?: string, description?: string, notes?: string, project?: string}  $overrides
     */
    private function makeBon(array $overrides = []): Voucher
    {
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'company' => $overrides['company'] ?? 'Harm Wesselink',
            'email' => 'harm@example.test',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => $overrides['project'] ?? 'Laakse Tuinen Amersfoort',
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
        ]);
        $voucher = Voucher::query()->create([
            'number' => 'BON-2026-0002',
            'type' => VoucherType::Facturatie,
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'issued_on' => '2026-09-03',
            'total_amount' => 165,
            'notes' => $overrides['notes'] ?? null,
        ]);
        $voucher->lines()->create([
            'description' => $overrides['description'] ?? 'Primen & Egaliseren',
            'quantity' => 30,
            'unit' => 'm2',
            'unit_price' => 5.50,
            'amount' => 165,
        ]);

        return $voucher->fresh(['worker', 'project', 'lines']);
    }
}
