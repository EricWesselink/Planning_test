<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class WorkerPdfImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_team_pdf_preview(): void
    {
        $this->post(route('workers.pdf.preview'), [
            'pdf' => $this->pdf("Naam: Team Wespro\nType: ZZP"),
        ])->assertRedirect(route('login'));
    }

    public function test_uitvoerder_is_forbidden_from_team_pdf_preview(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $this->actingAs($user)->post(route('workers.pdf.preview'), [
            'pdf' => $this->pdf("Naam: Team Wespro\nType: ZZP"),
        ])->assertForbidden();

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertDontSee('PDF met team')
            ->assertDontSee('PDF uitlezen');

        $this->assertSame(0, Worker::query()->count());
    }

    public function test_vakmensen_overview_shows_team_pdf_upload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workers.index'))
            ->assertOk()
            ->assertSee('PDF met team')
            ->assertSee('PDF uitlezen')
            ->assertSee('name="pdf"', false)
            ->assertSee('accept=".pdf,application/pdf"', false);
    }

    public function test_team_pdf_fills_the_create_form(): void
    {
        $user = User::factory()->create();

        $preview = $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.pdf.preview'), [
                'pdf' => $this->pdf(<<<'TXT'
Naam: Team Wespro
Type: ZZP
Personen: 3
Namen: Kees, Piet, Jan
Vakkennis: PVC, Vinyl
E-mail: wespro@example.nl
TXT),
            ]);

        $preview
            ->assertRedirect(route('workers.index'))
            ->assertSessionHas('status', 'PDF uitgelezen. Controleer de gegevens en klik op Toevoegen.');

        $this->followRedirects($preview)
            ->assertOk()
            ->assertSee('value="Team Wespro"', false)
            ->assertSee('value="zzp"', false)
            ->assertSee('value="3"', false)
            ->assertSee('value="pvc"', false)
            ->assertSee('value="vinyl"', false)
            ->assertSee('value="wespro@example.nl"', false)
            ->assertSee('name="crew_names"', false)
            ->assertSee('value="Kees, Piet, Jan"', false)
            ->assertSee('Uit PDF: Kees, Piet, Jan')
            ->assertSee('value="pvc" class="size-4 shrink-0 accent-nicon-ok" checked', false)
            ->assertSee('value="vinyl" class="size-4 shrink-0 accent-nicon-ok" checked', false);
    }

    public function test_team_from_pdf_can_be_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.store'), [
                'name' => 'Team Wespro',
                'employment_type' => 'zzp',
                'people_count' => '3',
                'specialties' => ['pvc', 'vinyl'],
                'crew_names' => 'Kees, Piet, Jan',
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasNoErrors();

        $worker = Worker::query()->where('name', 'Team Wespro')->first();
        $this->assertNotNull($worker);
        $this->assertSame('zzp', $worker->employment_type->value);
        $this->assertSame(3, $worker->people_count);
        $this->assertSame('PVC, Vinyl', $worker->specialty);
        $this->assertSame('Kees, Piet, Jan', $worker->crew_names);
    }

    public function test_pdf_without_a_team_name_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.pdf.preview'), [
                'pdf' => $this->pdf("PVC\nLinoleum"),
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors(['pdf' => 'In deze PDF staat geen team dat we kunnen uitlezen.']);

        $this->assertSame(0, Worker::query()->count());
    }

    public function test_non_pdf_upload_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('workers.index'))
            ->post(route('workers.pdf.preview'), [
                'pdf' => UploadedFile::fake()->create('team.txt', 10, 'text/plain'),
            ])
            ->assertRedirect(route('workers.index'))
            ->assertSessionHasErrors('pdf');

        $this->assertSame(0, Worker::query()->count());
    }

    private function pdf(string $text): UploadedFile
    {
        return new UploadedFile(SimplePdf::path($text), 'team.pdf', 'application/pdf', null, true);
    }
}
