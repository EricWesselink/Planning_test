<?php

namespace Tests\Feature;

use App\Enums\AvailabilityKind;
use App\Enums\LeaveRequestStatus;
use App\Enums\UserRole;
use App\Mail\LeaveRequestApprovedMail;
use App\Mail\LeaveRequestRejectedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Customer;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkerAvailability;
use App\Models\WorkItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_eigen_vakman_can_request_a_single_day_off(): void
    {
        Mail::fake();
        $this->travelTo('2026-09-19 10:00:00');
        [$nick, $worker] = $this->makeEigenVakman();
        $admin = $this->makeAdmin();

        $this->actingAs($nick)
            ->get(route('vakman.planning', ['view' => 'week', 'week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('Vrij aanvragen');

        $this->actingAs($nick)
            ->get(route('vakman.leave-requests.index'))
            ->assertOk()
            ->assertSee('name="date" value="2026-09-19"', false)
            ->assertDontSee('name="starts_on" value="2026-09-19"', false)
            ->assertDontSee('name="ends_on" value="2026-09-19"', false);

        $this->actingAs($nick)
            ->from(route('vakman.leave-requests.index'))
            ->post(route('vakman.leave-requests.store'), [
                'span' => 'single',
                'date' => '2026-09-28',
                'note' => 'Tandarts',
            ])
            ->assertRedirect(route('vakman.leave-requests.index'))
            ->assertSessionHas('status', 'Je aanvraag is verstuurd en wacht op goedkeuring.');

        $request = LeaveRequest::query()->first();
        $this->assertNotNull($request);
        $this->assertSame($nick->id, $request->user_id);
        $this->assertSame($worker->id, $request->worker_id);
        $this->assertSame('2026-09-28', $request->starts_on->toDateString());
        $this->assertSame('2026-09-28', $request->ends_on->toDateString());
        $this->assertSame(LeaveRequestStatus::Pending, $request->status);
        $this->assertSame('Tandarts', $request->note);
        $this->assertSame(0, WorkerAvailability::query()->count());

        Mail::assertSent(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($admin): bool {
            return $mail->hasTo($admin->email)
                && $mail->envelope()->subject === 'Vrij-aanvraag Nick Seine – 28 september 2026';
        });

        $this->actingAs($nick)
            ->get(route('vakman.leave-requests.index'))
            ->assertOk()
            ->assertSee('In behandeling')
            ->assertSee('Je aanvraag is verstuurd en wacht op goedkeuring.');
    }

    public function test_eigen_vakman_can_request_a_period(): void
    {
        Mail::fake();
        $this->travelTo('2026-09-19 10:00:00');
        [$nick] = $this->makeEigenVakman();
        $this->makeAdmin();

        $this->actingAs($nick)
            ->post(route('vakman.leave-requests.store'), [
                'span' => 'range',
                'starts_on' => '2026-09-28',
                'ends_on' => '2026-10-02',
                'note' => 'Vakantie',
            ])
            ->assertRedirect(route('vakman.leave-requests.index'));

        $request = LeaveRequest::query()->first();
        $this->assertSame('2026-09-28', $request->starts_on->toDateString());
        $this->assertSame('2026-10-02', $request->ends_on->toDateString());
        $this->assertSame(5, $request->workdayCount());
        $this->assertSame(LeaveRequestStatus::Pending, $request->status);
        $this->assertSame(0, WorkerAvailability::query()->count());
    }

    public function test_pending_request_does_not_mark_the_vakman_absent(): void
    {
        $this->travelTo('2026-09-19 10:00:00');
        [$nick, $worker] = $this->makeEigenVakman();
        $planner = User::factory()->create();
        $this->makeLeaveRequest($nick, $worker);

        $this->assertSame(0, WorkerAvailability::query()->count());

        $this->actingAs($planner)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('planning-available-name">Nick Seine</span>', false)
            ->assertSee('planning-avail-cell is-ok', false);

        $this->actingAs($nick)
            ->get(route('vakman.planning', ['view' => 'week', 'week' => '2026-09-28']))
            ->assertOk()
            ->assertDontSee('VRIJE DAG');
    }

    public function test_other_vakman_cannot_view_or_withdraw_the_request(): void
    {
        [$nick, $worker] = $this->makeEigenVakman();
        [$other] = $this->makeEigenVakman('Kees Jansen', 'kees@niconvloeren.nl');
        $request = $this->makeLeaveRequest($nick, $worker);

        $this->actingAs($other)
            ->get(route('leave-requests.show', $request))
            ->assertNotFound();

        $this->actingAs($other)
            ->from(route('vakman.leave-requests.index'))
            ->post(route('vakman.leave-requests.withdraw', $request))
            ->assertNotFound();

        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_vakman_cannot_approve_own_request(): void
    {
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker);

        $this->actingAs($nick)
            ->post(route('leave-requests.approve', $request))
            ->assertForbidden();

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(0, WorkerAvailability::query()->count());
    }

    #[DataProvider('nonAdminReviewers')]
    public function test_non_admin_cannot_approve_or_reject(UserRole $role): void
    {
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker);
        $actor = User::factory()->create(['role' => $role]);

        $this->actingAs($actor)
            ->post(route('leave-requests.approve', $request))
            ->assertForbidden();
        $this->actingAs($actor)
            ->post(route('leave-requests.reject', $request), ['rejection_reason' => 'Nee'])
            ->assertForbidden();

        $this->assertTrue($request->fresh()->isPending());
        $this->assertSame(0, WorkerAvailability::query()->count());
    }

    public function test_planner_can_view_requests_but_not_the_review_buttons(): void
    {
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker, '2026-09-28', '2026-10-02');
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('leave-requests.index'))
            ->assertOk()
            ->assertSee('Vrij-aanvragen (1)')
            ->assertSee('Nick Seine')
            ->assertSee('28-09-2026 t/m 02-10-2026');

        $this->actingAs($planner)
            ->get(route('leave-requests.show', $request))
            ->assertOk()
            ->assertDontSee('>Goedkeuren</button>', false)
            ->assertDontSee('>Afwijzen</button>', false);
    }

    public function test_admin_sees_existing_planning_conflicts_before_approval(): void
    {
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker, '2026-09-28', '2026-10-02');
        $this->scheduleWorker($worker, '2026-09-28', '2026-09-29', 'Laakse Tuinen', 'PVC leggen');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('leave-requests.show', $request))
            ->assertOk()
            ->assertSee('Let op: Nick Seine staat in deze periode al ingepland.')
            ->assertSee('28-09-2026')
            ->assertSee('Laakse Tuinen')
            ->assertSee('PVC leggen')
            ->assertSee('Hele dag');
    }

    public function test_approving_creates_existing_absence_and_shows_it_in_planning(): void
    {
        Mail::fake();
        $this->travelTo('2026-09-19 10:00:00');
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker, '2026-09-28', '2026-10-02', 'Vakantie');
        $admin = $this->makeAdmin();
        $planner = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('leave-requests.show', $request))
            ->post(route('leave-requests.approve', $request))
            ->assertRedirect(route('leave-requests.show', $request))
            ->assertSessionHas('status', 'Aanvraag goedgekeurd. De afwezigheid staat nu in de planning.');

        $request->refresh();
        $this->assertSame(LeaveRequestStatus::Approved, $request->status);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->worker_availability_id);

        $availability = WorkerAvailability::query()->first();
        $this->assertNotNull($availability);
        $this->assertSame($worker->id, $availability->worker_id);
        $this->assertSame('2026-09-28', $availability->start_date->toDateString());
        $this->assertSame('2026-10-02', $availability->end_date->toDateString());
        $this->assertSame(AvailabilityKind::DayOff, $availability->kind);
        $this->assertSame($availability->id, $request->worker_availability_id);

        $this->actingAs($planner)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('planning-avail-cell is-away', false)
            ->assertSee('>Vrije dag</button>', false);

        $this->actingAs($nick)
            ->get(route('vakman.planning', ['view' => 'week', 'week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('VRIJE DAG');

        $this->actingAs($nick)
            ->get(route('vakman.leave-requests.index'))
            ->assertOk()
            ->assertSee('Goedgekeurd');

        Mail::assertSent(LeaveRequestApprovedMail::class, function (LeaveRequestApprovedMail $mail) use ($nick): bool {
            $mail->assertSeeInHtml('Je vrij-aanvraag voor 28 september 2026 t/m 2 oktober 2026 is goedgekeurd.');
            $mail->assertSeeInHtml('Deze periode staat nu als afwezig in je planning.');

            return $mail->hasTo($nick->email)
                && $mail->envelope()->subject === 'Je vrij-aanvraag is goedgekeurd';
        });
    }

    public function test_rejecting_does_not_create_absence_and_mails_the_reason(): void
    {
        Mail::fake();
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker, '2026-09-28', '2026-10-02');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('leave-requests.show', $request))
            ->post(route('leave-requests.reject', $request), [
                'rejection_reason' => 'Te druk op de bouw.',
            ])
            ->assertRedirect(route('leave-requests.show', $request));

        $request->refresh();
        $this->assertSame(LeaveRequestStatus::Rejected, $request->status);
        $this->assertSame('Te druk op de bouw.', $request->rejection_reason);
        $this->assertNull($request->worker_availability_id);
        $this->assertSame(0, WorkerAvailability::query()->count());

        $this->actingAs($nick)
            ->get(route('vakman.leave-requests.index'))
            ->assertOk()
            ->assertSee('Afgewezen')
            ->assertSee('Te druk op de bouw.');

        Mail::assertSent(LeaveRequestRejectedMail::class, function (LeaveRequestRejectedMail $mail) use ($nick): bool {
            $mail->assertSeeInHtml('Je vrij-aanvraag voor 28 september 2026 t/m 2 oktober 2026 is afgewezen.');
            $mail->assertSeeInHtml('Te druk op de bouw.');

            return $mail->hasTo($nick->email)
                && $mail->envelope()->subject === 'Je vrij-aanvraag is afgewezen';
        });
    }

    public function test_approved_request_cannot_be_approved_again(): void
    {
        Mail::fake();
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('leave-requests.approve', $request));
        $this->actingAs($admin)
            ->from(route('leave-requests.show', $request))
            ->post(route('leave-requests.approve', $request))
            ->assertForbidden();

        $this->assertSame(1, WorkerAvailability::query()->count());
        $this->assertSame(LeaveRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_vakman_can_withdraw_only_while_pending(): void
    {
        Mail::fake();
        [$nick, $worker] = $this->makeEigenVakman();
        $pending = $this->makeLeaveRequest($nick, $worker, '2026-09-28', '2026-09-28');
        $approved = $this->makeLeaveRequest($nick, $worker, '2026-10-05', '2026-10-05');
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post(route('leave-requests.approve', $approved));

        $this->actingAs($nick)
            ->from(route('vakman.leave-requests.index'))
            ->post(route('vakman.leave-requests.withdraw', $pending))
            ->assertRedirect(route('vakman.leave-requests.index'))
            ->assertSessionHas('status', 'De aanvraag is ingetrokken.');

        $this->assertSame(LeaveRequestStatus::Withdrawn, $pending->fresh()->status);
        $this->assertSame(1, WorkerAvailability::query()->count());

        $this->actingAs($nick)
            ->from(route('vakman.leave-requests.index'))
            ->post(route('vakman.leave-requests.withdraw', $approved))
            ->assertForbidden();

        $this->assertSame(LeaveRequestStatus::Approved, $approved->fresh()->status);
    }

    public function test_zzp_vakman_cannot_request_leave(): void
    {
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $vakman = User::factory()->vakman($worker->id)->create(['name' => 'Team Wespro']);

        $this->actingAs($vakman)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertDontSee('>Vrij aanvragen</a>', false);

        $this->actingAs($vakman)
            ->get(route('vakman.leave-requests.index'))
            ->assertForbidden();

        $this->actingAs($vakman)
            ->post(route('vakman.leave-requests.store'), [
                'span' => 'single',
                'date' => '2026-09-28',
            ])
            ->assertForbidden();
    }

    public function test_admin_show_escapes_the_vakman_note(): void
    {
        [$nick, $worker] = $this->makeEigenVakman();
        $request = $this->makeLeaveRequest($nick, $worker);
        $request->update(['note' => '<script>alert(1)</script>']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('leave-requests.show', $request))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<script>alert(1)</script>');
    }

    /**
     * @return array<string, list<UserRole>>
     */
    public static function nonAdminReviewers(): array
    {
        return [
            'planner' => [UserRole::Planner],
            'uitvoerder' => [UserRole::Uitvoerder],
            'projectleider' => [UserRole::Projectleider],
        ];
    }

    /**
     * @return array{0: User, 1: Worker}
     */
    private function makeEigenVakman(string $name = 'Nick Seine', string $email = 'nick@niconvloeren.nl'): array
    {
        $worker = Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $user = User::factory()->vakman($worker->id)->create([
            'name' => $name,
            'email' => $email,
        ]);

        return [$user, $worker];
    }

    private function makeAdmin(): User
    {
        return User::factory()->admin()->create([
            'name' => 'Eric Wesselink',
            'email' => 'eric@niconvloeren.nl',
        ]);
    }

    private function makeLeaveRequest(
        User $vakman,
        Worker $worker,
        string $startsOn = '2026-09-28',
        string $endsOn = '2026-09-28',
        ?string $note = null,
    ): LeaveRequest {
        return LeaveRequest::factory()->create([
            'user_id' => $vakman->id,
            'worker_id' => $worker->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'note' => $note,
        ]);
    }

    private function scheduleWorker(
        Worker $worker,
        string $start,
        string $end,
        string $projectName,
        string $workName,
    ): void {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => $projectName,
            'city' => 'Zwolle',
            'status' => 'in_uitvoering',
            'planned_start_date' => $start,
            'planned_end_date' => $end,
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $workName,
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(Carbon::parse($start), Carbon::parse($end), '08:00', '16:00');
        $assignment->save();
    }
}
