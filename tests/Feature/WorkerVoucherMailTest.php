<?php

namespace Tests\Feature;

use App\Enums\VoucherType;
use App\Mail\WorkerVoucherMail;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Voucher;
use App\Models\Worker;
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
