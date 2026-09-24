<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\WorkUnit;
use App\Mail\DocumentPdfMail;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\DocumentMail;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PlanningDocumentMailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
    }

    public function test_weekplanning_mail_attaches_the_filtered_pdf(): void
    {
        Mail::fake();
        $user = User::factory()->create(['name' => 'Eric Wesselink']);
        $wepro = $this->assign('Wepro', 'Laakse Tuinen');
        $this->assign('Andere Ploeg', 'Ander Werk');

        $this->actingAs($user)
            ->post(route('planning.weekplanning.email'), [
                'week' => '2026-09-07',
                'teams' => ['worker-'.$wepro->id],
                'recipient' => 'planning@example.nl',
                'cc' => 'cc@example.nl',
                'subject' => 'Planning Nicon Vloeren - week 37',
                'body' => "Goedendag,\n\nHierbij de planning.\n\nEric Wesselink",
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Mail::assertSent(DocumentPdfMail::class, function (DocumentPdfMail $mail): bool {
            return $mail->hasTo('planning@example.nl')
                && $mail->hasCc('cc@example.nl')
                && $mail->hasSubject('Planning Nicon Vloeren - week 37')
                && $mail->filename === 'weekplanning-Wepro-week-37-2026.pdf'
                && str_starts_with($mail->pdfBinary, '%PDF')
                && $mail->attachments()[0]->as === 'weekplanning-Wepro-week-37-2026.pdf';
        });

        $download = $this->actingAs($user)->get(route('planning.weekplanning', [
            'week' => '2026-09-07',
            'teams' => ['worker-'.$wepro->id],
        ]));
        $download->assertOk();
        $this->assertStringContainsString('weekplanning-Wepro-week-37-2026.pdf', (string) $download->headers->get('content-disposition'));
        $this->assertSame('%PDF', substr($download->getContent(), 0, 4));

        $this->assertDatabaseHas('document_mails', [
            'user_id' => $user->id,
            'recipient' => 'planning@example.nl',
            'cc' => 'cc@example.nl',
            'document_type' => 'weekplanning',
            'attachment_filename' => 'weekplanning-Wepro-week-37-2026.pdf',
            'status' => 'verzonden',
            'project_id' => null,
        ]);
    }

    public function test_personnel_week_mail_uses_the_same_pdf(): void
    {
        Mail::fake();
        $user = User::factory()->create(['name' => 'Eric Wesselink']);
        $this->assign('Nick Seine', 'Laakse Tuinen');

        $this->actingAs($user)
            ->get(route('planning.personnel-week', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('E-mailen')
            ->assertSee('Weekplanning personeel PDF')
            ->assertSee('Eric Wesselink');

        $this->actingAs($user)
            ->post(route('planning.personnel-week.email'), [
                'week' => '2026-09-07',
                'recipient' => 'personeel@example.nl',
                'subject' => 'Personeelsplanning Nicon Vloeren - week 37',
                'body' => 'Goedendag, hierbij de personeelsplanning.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Mail::assertSent(DocumentPdfMail::class, function (DocumentPdfMail $mail): bool {
            return $mail->hasTo('personeel@example.nl')
                && $mail->filename === 'weekplanning-personeel-week-37-2026.pdf'
                && str_starts_with($mail->pdfBinary, '%PDF')
                && $mail->mailSubject === 'Personeelsplanning Nicon Vloeren - week 37';
        });

        $download = $this->actingAs($user)->get(route('planning.personnel-week.pdf', ['week' => '2026-09-07']));
        $download->assertOk();
        $this->assertSame('%PDF', substr($download->getContent(), 0, 4));
        $this->assertStringContainsString('weekplanning-personeel-week-37-2026.pdf', (string) $download->headers->get('content-disposition'));
    }

    public function test_werkbon_and_opdrachtbon_can_be_mailed_and_listed_on_the_project(): void
    {
        Mail::fake();
        $planner = User::factory()->create(['name' => 'Eric Wesselink']);
        $werkbon = $this->ticket($planner);
        $opdrachtbon = $this->ticket($planner, zzp: true);
        $werkbon->project->forceFill(['contact_email' => 'klant@example.nl'])->save();

        $this->actingAs($planner)
            ->get(route('work-tickets.show', $werkbon))
            ->assertOk()
            ->assertSee('Download PDF')
            ->assertSee('Afdrukken')
            ->assertSee('E-mailen')
            ->assertSee('value="klant@example.nl"', false);

        $this->actingAs($planner)
            ->post(route('work-tickets.email', $werkbon), [
                'recipient' => 'klant@example.nl',
                'subject' => 'Werkbon '.$werkbon->number.' - '.$werkbon->project->displayTitle(),
                'body' => "Goedendag,\n\nHierbij de werkbon.\n\nEric Wesselink",
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->actingAs($planner)
            ->post(route('work-tickets.email', $opdrachtbon), [
                'recipient' => 'zzp@example.nl',
                'subject' => 'Opdrachtbon '.$opdrachtbon->number.' - '.$opdrachtbon->project->displayTitle(),
                'body' => 'Goedendag, hierbij de opdrachtbon.',
            ])
            ->assertRedirect();

        $filenames = [];
        Mail::assertSent(DocumentPdfMail::class, function (DocumentPdfMail $mail) use (&$filenames, $werkbon): bool {
            if (! $mail->hasTo('klant@example.nl')) {
                return false;
            }
            $filenames[] = $mail->filename;

            return str_starts_with($mail->pdfBinary, '%PDF')
                && str_contains($mail->filename, $werkbon->number);
        });
        Mail::assertSent(DocumentPdfMail::class, function (DocumentPdfMail $mail) use ($opdrachtbon): bool {
            return $mail->hasTo('zzp@example.nl')
                && str_starts_with($mail->pdfBinary, '%PDF')
                && str_contains($mail->filename, $opdrachtbon->number);
        });

        $download = $this->actingAs($planner)->get(route('work-tickets.pdf', $werkbon));
        $download->assertOk();
        $this->assertSame('%PDF', substr($download->getContent(), 0, 4));
        $this->assertStringContainsString($filenames[0], (string) $download->headers->get('content-disposition'));

        $this->assertDatabaseHas('document_mails', [
            'work_ticket_id' => $werkbon->id,
            'project_id' => $werkbon->project_id,
            'document_type' => 'werkbon',
            'status' => 'verzonden',
            'recipient' => 'klant@example.nl',
        ]);
        $this->assertDatabaseHas('document_mails', [
            'work_ticket_id' => $opdrachtbon->id,
            'document_type' => 'opdrachtbon',
            'status' => 'verzonden',
        ]);

        $index = $this->actingAs($planner)->get(route('projects.emails', $werkbon->project));
        $index->assertOk()
            ->assertSee('Werkbon '.$werkbon->number)
            ->assertSee('klant@example.nl')
            ->assertSee('Verzonden')
            ->assertSee('Eric Wesselink')
            ->assertDontSee('Hierbij de werkbon');

        $stored = DocumentMail::query()->where('work_ticket_id', $werkbon->id)->first();
        $this->actingAs($planner)
            ->get(route('projects.emails.show', [$werkbon->project, $stored]))
            ->assertOk()
            ->assertSee('Hierbij de werkbon');
    }

    public function test_invalid_email_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->assign('Nick Seine', 'Laakse Tuinen');

        $this->actingAs($user)
            ->from(route('planning'))
            ->post(route('planning.weekplanning.email'), [
                'week' => '2026-09-07',
                'all' => '1',
                'recipient' => 'geen-adres',
                'cc' => 'ook-fout',
                'subject' => 'Planning',
                'body' => 'Bericht',
            ])
            ->assertRedirect(route('planning'))
            ->assertSessionHasErrors(['recipient', 'cc'], null, 'document_mail');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('document_mails', 0);
    }

    public function test_user_without_pdf_permission_cannot_mail_the_weekplanning(): void
    {
        $user = User::factory()->aangepast([Permission::PlanningView])->create();

        $this->actingAs($user)
            ->post(route('planning.weekplanning.email'), [
                'week' => '2026-09-07',
                'all' => '1',
                'recipient' => 'planning@example.nl',
                'subject' => 'Planning',
                'body' => 'Bericht',
            ])
            ->assertForbidden();
    }

    public function test_user_cannot_mail_a_work_ticket_of_another_project(): void
    {
        $planner = User::factory()->create();
        $ticket = $this->ticket($planner);
        $other = $this->project('Ander werk');
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($other);

        $this->actingAs($user)
            ->post(route('work-tickets.email', $ticket), [
                'recipient' => 'klant@example.nl',
                'subject' => 'Werkbon',
                'body' => 'Bericht',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('document_mails', 0);
    }

    public function test_project_mail_history_is_not_readable_from_another_project(): void
    {
        Mail::fake();
        $planner = User::factory()->create();
        $ticket = $this->ticket($planner);
        $this->actingAs($planner)->post(route('work-tickets.email', $ticket), [
            'recipient' => 'klant@example.nl',
            'subject' => 'Werkbon '.$ticket->number,
            'body' => 'Geheim bericht voor dit werk.',
        ])->assertRedirect();
        $stored = DocumentMail::query()->first();
        $other = $this->project('Ander werk');
        $user = User::factory()->limitedAccess()->create();
        $user->projects()->attach($other);
        $user->projects()->attach($ticket->project);

        $this->actingAs($user)
            ->get(route('projects.emails.show', [$other, $stored]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('projects.emails', $ticket->project))
            ->assertOk()
            ->assertSee('klant@example.nl');
    }

    public function test_failed_mail_is_stored_without_smtp_details(): void
    {
        $user = User::factory()->create();
        $this->assign('Nick Seine', 'Laakse Tuinen');
        Event::listen(MessageSending::class, function (): void {
            throw new RuntimeException('smtp password=secret-token');
        });

        $this->actingAs($user)
            ->from(route('planning'))
            ->post(route('planning.weekplanning.email'), [
                'week' => '2026-09-07',
                'all' => '1',
                'recipient' => 'planning@example.nl',
                'subject' => 'Planning',
                'body' => 'Bericht',
            ])
            ->assertRedirect(route('planning'))
            ->assertSessionHasErrors(['email' => 'De e-mail kon niet worden verzonden. Probeer het later opnieuw.'], null, 'document_mail');

        $stored = DocumentMail::query()->first();
        $this->assertNotNull($stored);
        $this->assertSame('mislukt', $stored->status->value);
        $this->assertSame('De e-mail kon niet worden verzonden.', $stored->error_message);
        $this->assertStringNotContainsString('secret-token', (string) $stored->error_message);
    }

    public function test_planning_print_page_still_opens(): void
    {
        $user = User::factory()->create();
        $assignment = $this->assign('Nick Seine', 'Laakse Tuinen');

        $this->actingAs($user)
            ->get(route('planning.export', [
                'project_id' => $assignment->project_id,
                'period' => 'work',
                'print' => 1,
            ]))
            ->assertOk()
            ->assertSee('Opslaan als PDF');
    }

    private function assign(string $workerName, string $projectName): Worker
    {
        $worker = Worker::query()->create([
            'name' => $workerName,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $project = $this->project($projectName);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 10,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'hours_per_day' => 8,
        ]);

        return $worker;
    }

    private function project(string $name): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Gemeente']);

        return Project::query()->create([
            'project_number' => '2609'.str_pad((string) (Project::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
        ]);
    }

    private function ticket(User $planner, bool $zzp = false): WorkTicket
    {
        $worker = Worker::query()->create([
            'name' => $zzp ? 'Het Vloerenhuis' : 'Nick Seine',
            'employment_type' => $zzp ? 'zzp' : 'eigen',
            'company' => $zzp ? 'Het Vloerenhuis' : null,
            'active' => true,
        ]);
        $project = $this->project($zzp ? 'Opdrachtwerk' : 'Gezondheidscentrum Laren');
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'Begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.01',
            'name' => 'hal',
            'square_meters' => 12,
            'status' => 'niet_gestart',
            'sort_order' => 1,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 12,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => 12,
            'unit' => WorkUnit::SquareMeter,
            'status' => 'niet_gestart',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-18',
            'hours_per_day' => 8,
        ]);
        $payload = [
            'floors' => [
                $floor->id => [
                    'included' => '1',
                    'scope' => 'entire',
                ],
            ],
            'work_item_ids' => [$item->id],
        ];
        if ($zzp) {
            $payload['billing_method'] = 'fixed';
            $payload['fixed_price'] = '100';
        }

        $this->actingAs($planner)->post(route('work-tickets.store', $assignment), $payload)->assertSessionHasNoErrors()->assertRedirect();

        return WorkTicket::query()->where('worker_assignment_id', $assignment->id)->firstOrFail();
    }
}
