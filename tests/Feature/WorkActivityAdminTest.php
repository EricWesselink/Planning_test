<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkActivityAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_catalog(): void
    {
        $this->get(route('work-activities.index'))->assertRedirect(route('login'));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_manage_catalog(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('work-activities.index'))->assertForbidden();
        $this->actingAs($user)->post(route('work-activities.store'), [
            'work_activity_category_id' => WorkActivityCategory::query()->value('id'),
            'name' => 'Horren',
        ])->assertForbidden();
    }

    public function test_admin_can_add_rename_recategorize_and_disable_an_activity(): void
    {
        $admin = User::factory()->admin()->create();
        $overig = WorkActivityCategory::query()->where('slug', 'overig')->firstOrFail();
        $zonwering = WorkActivityCategory::query()->where('slug', 'zonwering')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('work-activities.index'))
            ->assertOk()
            ->assertSee('Winkelwerkzaamheden')
            ->assertSee('Vloeren')
            ->assertSee('Screens');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.url('/beheer/werkzaamheden').'"', false);

        $this->actingAs($admin)
            ->post(route('work-activity-categories.store'), [
                'name' => 'Horren',
            ])
            ->assertRedirect();

        $horren = WorkActivityCategory::query()->where('slug', 'horren')->first();
        $this->assertNotNull($horren);
        $this->assertSame('Horren', $horren->name);

        $this->actingAs($admin)
            ->post(route('work-activities.store'), [
                'work_activity_category_id' => $overig->id,
                'name' => 'Vloeronderhoud',
            ])
            ->assertRedirect();

        $activity = WorkActivity::query()->where('slug', 'vloeronderhoud')->first();
        $this->assertNotNull($activity);
        $this->assertSame($overig->id, $activity->work_activity_category_id);

        $this->actingAs($admin)
            ->patch(route('work-activities.update', $activity), [
                'work_activity_category_id' => $zonwering->id,
                'name' => 'Vloeronderhoud plus',
                'sort_order' => 12,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $activity->refresh();
        $this->assertSame($zonwering->id, $activity->work_activity_category_id);
        $this->assertSame('Vloeronderhoud plus', $activity->name);
        $this->assertSame(12, $activity->sort_order);

        $this->actingAs($admin)
            ->patch(route('work-activities.update', $activity), [
                'work_activity_category_id' => $zonwering->id,
                'name' => 'Vloeronderhoud plus',
                'sort_order' => 12,
                'is_active' => '0',
            ])
            ->assertRedirect();

        $this->assertFalse($activity->fresh()->is_active);

        $planner = User::factory()->create();
        $this->actingAs($planner)
            ->get(route('projects.winkel.create'))
            ->assertOk()
            ->assertDontSee('Vloeronderhoud plus')
            ->assertSee('Screens');
    }

    public function test_planner_does_not_see_catalog_menu(): void
    {
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.url('/beheer/werkzaamheden').'"', false);
    }

    /** @return array<string, array{0: UserRole}> */
    public static function nonAdminRoles(): array
    {
        return [
            'planner' => [UserRole::Planner],
            'projectleider' => [UserRole::Projectleider],
            'uitvoerder' => [UserRole::Uitvoerder],
        ];
    }
}
