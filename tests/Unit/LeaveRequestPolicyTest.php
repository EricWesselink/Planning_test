<?php

namespace Tests\Unit;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_eigen_vakman_can_create_a_request(): void
    {
        $eigen = Worker::query()->create(['name' => 'Nick Seine', 'employment_type' => 'eigen', 'active' => true]);
        $zzp = Worker::query()->create(['name' => 'Team Wespro', 'employment_type' => 'zzp', 'active' => true]);
        $vakman = User::factory()->vakman($eigen->id)->create();
        $zzpUser = User::factory()->vakman($zzp->id)->create();

        $this->assertTrue($vakman->can('create', LeaveRequest::class));
        $this->assertFalse($zzpUser->can('create', LeaveRequest::class));
        $this->assertFalse(User::factory()->admin()->make()->can('create', LeaveRequest::class));
        $this->assertFalse(User::factory()->make()->can('create', LeaveRequest::class));
    }

    public function test_only_an_admin_may_review_a_pending_request(): void
    {
        $worker = Worker::query()->create(['name' => 'Nick Seine', 'employment_type' => 'eigen', 'active' => true]);
        $vakman = User::factory()->vakman($worker->id)->create();
        $request = LeaveRequest::factory()->create([
            'user_id' => $vakman->id,
            'worker_id' => $worker->id,
        ]);
        $admin = User::factory()->admin()->create();
        $planner = User::factory()->create();

        $this->assertTrue($admin->can('review', $request));
        $this->assertFalse($planner->can('review', $request));
        $this->assertFalse($vakman->can('review', $request));
        $this->assertTrue($planner->can('view', $request));
        $this->assertTrue($vakman->can('view', $request));
        $this->assertTrue($vakman->can('withdraw', $request));

        $request->update(['status' => 'goedgekeurd']);
        $this->assertFalse($admin->can('review', $request->fresh()));
        $this->assertFalse($vakman->can('withdraw', $request->fresh()));
    }

    public function test_another_vakman_cannot_view_or_withdraw_the_request(): void
    {
        $worker = Worker::query()->create(['name' => 'Nick Seine', 'employment_type' => 'eigen', 'active' => true]);
        $otherWorker = Worker::query()->create(['name' => 'Kees Jansen', 'employment_type' => 'eigen', 'active' => true]);
        $owner = User::factory()->vakman($worker->id)->create();
        $other = User::factory()->vakman($otherWorker->id)->create();
        $request = LeaveRequest::factory()->create([
            'user_id' => $owner->id,
            'worker_id' => $worker->id,
        ]);

        $this->assertFalse($other->can('view', $request));
        $this->assertFalse($other->can('withdraw', $request));
        $this->assertFalse($other->can('viewAny', LeaveRequest::class));
    }
}
