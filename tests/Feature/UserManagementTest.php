<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_user_management(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
        $this->get(route('users.create'))->assertRedirect(route('login'));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_open_user_management(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $target = User::factory()->create(['role' => UserRole::Planner]);

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('users.create'))->assertForbidden();
        $this->actingAs($user)->get(route('users.show', $target))->assertForbidden();
        $this->actingAs($user)->post(route('users.store'), $this->payload())->assertForbidden();
        $this->actingAs($user)
            ->patch(route('users.update', $target), $this->payload([
                'name' => $target->name,
                'email' => $target->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Admin->value,
                'permissions' => [Permission::UsersManage->value],
            ]))
            ->assertForbidden();

        $this->assertSame(UserRole::Planner, $target->fresh()->role);
    }

    public function test_admin_can_view_users_and_sees_the_menu(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Eric']);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Gebruikersbeheer')
            ->assertSee('Eric')
            ->assertSee('Bewerken')
            ->assertSee('Laatst ingelogd')
            ->assertSee('Nog niet');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.url('/gebruikers').'"', false);
    }

    public function test_planner_does_not_see_the_users_menu(): void
    {
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.url('/gebruikers').'"', false);
    }

    public function test_admin_can_create_a_user_with_selected_projects(): void
    {
        $admin = User::factory()->admin()->create();
        $projectA = $this->makeProject('Project A');
        $projectB = $this->makeProject('Project B');

        $this->actingAs($admin)
            ->post(route('users.store'), $this->payload([
                'name' => 'Uitvoerder Jan',
                'email' => 'jan@niconvloeren.nl',
                'role' => UserRole::Uitvoerder->value,
                'project_access' => 'selected',
                'project_ids' => [$projectA->id],
            ]))
            ->assertRedirect();

        $jan = User::query()->where('email', 'jan@niconvloeren.nl')->first();
        $this->assertNotNull($jan);
        $this->assertSame('Uitvoerder Jan', $jan->name);
        $this->assertSame(UserRole::Uitvoerder, $jan->role);
        $this->assertSame(true, $jan->active);
        $this->assertSame(false, $jan->can_access_all_projects);
        $this->assertTrue($jan->projects->contains($projectA));
        $this->assertFalse($jan->projects->contains($projectB));
        $this->assertTrue(Hash::check('wachtwoord123', $jan->password));
    }

    public function test_user_index_shows_edit_link_and_custom_role_label(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Eric']);
        $planner = User::factory()->create(['name' => 'Iris']);
        $custom = User::factory()->aangepast([Permission::PlanningView])->create(['name' => 'Berry']);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Bewerken')
            ->assertSee('Verwijderen')
            ->assertSee('Planner')
            ->assertSee('Aangepast')
            ->assertSee('Bekijk rechten')
            ->assertSee(route('users.show', $planner), false)
            ->assertSee(route('users.show', $custom).'#rechten', false)
            ->assertDontSee('Aangepaste rechten');
    }

    public function test_edit_form_shows_existing_user_details(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'name' => 'Iris',
            'email' => 'iris@niconvloeren.nl',
            'role' => UserRole::Planner,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('users.show', $user))
            ->assertOk()
            ->assertSee('Gebruiker bewerken')
            ->assertSee('value="Iris"', false)
            ->assertSee('value="iris@niconvloeren.nl"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="planner"[^>]*\bselected\b/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="rechten"[^>]*\bhidden\b/',
            $html,
        );
    }

    public function test_admin_can_change_existing_planner_to_read_only_on_the_same_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'name' => 'Iris',
            'email' => 'iris@niconvloeren.nl',
            'role' => UserRole::Planner,
        ]);
        $userId = $user->id;
        $count = User::query()->count();

        $this->actingAs($admin)
            ->patch(route('users.update', $user), $this->payload([
                'name' => 'Iris',
                'email' => 'iris@niconvloeren.nl',
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::AlleenLezen->value,
            ]))
            ->assertRedirect(route('users.show', $user));

        $user->refresh();
        $this->assertSame($userId, $user->id);
        $this->assertSame($count, User::query()->count());
        $this->assertSame('iris@niconvloeren.nl', $user->email);
        $this->assertSame(UserRole::AlleenLezen, $user->role);
        $this->assertNull($user->permissions);
        $this->assertTrue($user->canViewPlanning());
        $this->assertFalse($user->canManagePlanning());

        $this->actingAs($user)->get(route('planning'))->assertOk();
        $this->actingAs($user)
            ->patch(route('users.update', $admin), $this->payload([
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Planner->value,
            ]))
            ->assertForbidden();
    }

    public function test_admin_can_store_custom_permissions_on_an_existing_planner_and_reload_them(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'name' => 'Berry',
            'email' => 'berry@niconvloeren.nl',
            'role' => UserRole::Planner,
        ]);
        $userId = $user->id;

        $this->actingAs($admin)
            ->patch(route('users.update', $user), $this->payload([
                'name' => 'Berry',
                'email' => 'berry@niconvloeren.nl',
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Aangepast->value,
                'permissions' => [
                    Permission::PlanningView->value,
                    Permission::PlanningAssign->value,
                ],
            ]))
            ->assertRedirect(route('users.show', $user));

        $user->refresh();
        $this->assertSame($userId, $user->id);
        $this->assertSame(UserRole::Aangepast, $user->role);
        $this->assertSame(
            PermissionCatalog::sanitize([
                Permission::PlanningView->value,
                Permission::PlanningAssign->value,
            ]),
            $user->permissions,
        );
        $this->assertTrue($user->canAssignPlanning());
        $this->assertFalse($user->canViewCalculations());

        $html = $this->actingAs($admin)
            ->get(route('users.show', $user))
            ->assertOk()
            ->assertSee('Gebruiker bewerken')
            ->assertSee('Alles toestaan')
            ->assertSee('Alleen lezen')
            ->assertSee('Alles uit')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="aangepast"[^>]*\bselected\b/',
            $html,
        );
        $this->assertPermissionCheckbox($html, Permission::PlanningAssign, true);
        $this->assertPermissionCheckbox($html, Permission::PlanningView, true);
        $this->assertPermissionCheckbox($html, Permission::CalculationsView, false);
    }

    public function test_admin_can_change_custom_user_back_to_planner(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->aangepast([
            Permission::PlanningView,
            Permission::PlanningAssign,
        ])->create([
            'name' => 'Cuno',
            'email' => 'cuno@niconvloeren.nl',
        ]);
        $userId = $user->id;

        $this->actingAs($admin)
            ->patch(route('users.update', $user), $this->payload([
                'name' => 'Cuno',
                'email' => 'cuno@niconvloeren.nl',
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Planner->value,
            ]))
            ->assertRedirect(route('users.show', $user));

        $user->refresh();
        $this->assertSame($userId, $user->id);
        $this->assertSame(UserRole::Planner, $user->role);
        $this->assertNull($user->permissions);
        $this->assertTrue($user->canManagePlanning());
    }

    public function test_changing_an_office_user_does_not_alter_a_vakman_account(): void
    {
        $admin = User::factory()->admin()->create();
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'zzp',
            'company' => 'Nick Seine',
            'people_count' => 1,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $vakman = User::factory()->vakman($worker->id)->create([
            'name' => 'Nick Seine',
            'email' => 'nick@niconvloeren.nl',
        ]);
        $planner = User::factory()->create([
            'name' => 'Iris',
            'email' => 'iris@niconvloeren.nl',
            'role' => UserRole::Planner,
        ]);

        $this->actingAs($admin)
            ->patch(route('users.update', $planner), $this->payload([
                'name' => 'Iris',
                'email' => 'iris@niconvloeren.nl',
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::AlleenLezen->value,
            ]))
            ->assertRedirect(route('users.show', $planner));

        $vakman->refresh();
        $this->assertSame(UserRole::Vakman, $vakman->role);
        $this->assertSame($worker->id, $vakman->worker_id);
        $this->assertSame('nick@niconvloeren.nl', $vakman->email);
        $this->assertNull($vakman->permissions);
        $this->assertTrue($vakman->isVakman());

        $html = $this->actingAs($admin)
            ->get(route('users.show', $vakman))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="vakman"[^>]*\bselected\b/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="rechten"[^>]*\bhidden\b/',
            $html,
        );
    }

    public function test_admin_can_update_name_email_role_and_project_access(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'name' => 'Gerrit',
            'email' => 'gerrit@niconvloeren.nl',
            'role' => UserRole::Planner,
        ]);
        $projectA = $this->makeProject('Project A');
        $projectB = $this->makeProject('Project B');

        $this->actingAs($admin)
            ->patch(route('users.update', $user), $this->payload([
                'name' => 'Gerrit Bakker',
                'email' => 'g.bakker@niconvloeren.nl',
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Projectleider->value,
                'project_access' => 'selected',
                'project_ids' => [$projectA->id, $projectB->id],
            ]))
            ->assertRedirect(route('users.show', $user));

        $user->refresh();
        $this->assertSame('Gerrit Bakker', $user->name);
        $this->assertSame('g.bakker@niconvloeren.nl', $user->email);
        $this->assertSame(UserRole::Projectleider, $user->role);
        $this->assertSame(false, $user->can_access_all_projects);
        $this->assertEqualsCanonicalizing([$projectA->id, $projectB->id], $user->projects()->pluck('projects.id')->all());
    }

    public function test_admin_can_reset_a_password_so_the_user_can_log_in(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'email' => 'jan@niconvloeren.nl',
            'password' => 'oudwachtwoord',
        ]);

        $this->actingAs($admin)
            ->patch(route('users.update', $user), $this->payload([
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'nieuwWacht1',
                'password_confirmation' => 'nieuwWacht1',
                'role' => $user->role->value,
            ]))
            ->assertRedirect(route('users.show', $user));

        $this->assertTrue(Hash::check('nieuwWacht1', $user->fresh()->password));

        $this->post('/logout');
        $this->from(route('login'))->post('/login', [
            'email' => 'jan@niconvloeren.nl',
            'password' => 'nieuwWacht1',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_empty_create_payload_returns_validation_errors(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('users.create'))
            ->post(route('users.store'), [])
            ->assertRedirect(route('users.create'))
            ->assertSessionHasErrors(['name', 'email', 'password', 'role', 'project_access']);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $admin), $this->payload([
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Admin->value,
                'active' => '0',
            ]))
            ->assertForbidden();

        $this->assertSame(true, $admin->fresh()->active);
    }

    public function test_admin_cannot_demote_the_last_active_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $admin), $this->payload([
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Planner->value,
            ]))
            ->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_admin_cannot_delete_themselves_or_the_last_active_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertForbidden();

        $this->assertModelExists($admin);
    }

    public function test_admin_can_demote_and_delete_another_admin_when_one_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create(['name' => 'Marie']);

        $this->actingAs($admin)
            ->patch(route('users.update', $other), $this->payload([
                'name' => 'Marie',
                'email' => $other->email,
                'password' => '',
                'password_confirmation' => '',
                'role' => UserRole::Planner->value,
            ]))
            ->assertRedirect(route('users.show', $other));

        $this->assertSame(UserRole::Planner, $other->fresh()->role);

        $extra = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $extra))
            ->assertRedirect(route('users.index'));

        $this->assertModelMissing($extra);
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'uit@niconvloeren.nl',
            'password' => 'password',
            'active' => false,
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => 'uit@niconvloeren.nl',
            'password' => 'password',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'uit@niconvloeren.nl')->value('last_login_at'));
    }

    public function test_admin_sees_when_a_user_last_logged_in(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create(['name' => 'Eric']);
        User::factory()->create([
            'name' => 'Harm',
            'last_login_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Laatst ingelogd')
            ->assertSee(now()->subDays(2)->format('d-m-Y H:i'))
            ->assertSee('Nog niet');
    }

    public function test_successful_login_records_last_login_at(): void
    {
        $this->freezeTime();
        $user = User::factory()->create([
            'email' => 'planner@example.test',
            'last_login_at' => null,
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => 'planner@example.test',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertTrue($user->fresh()->last_login_at?->isSameSecond(now()));
    }

    public function test_failed_login_does_not_record_last_login_at(): void
    {
        $user = User::factory()->create([
            'email' => 'planner@example.test',
            'last_login_at' => null,
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => 'planner@example.test',
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'));

        $this->assertNull($user->fresh()->last_login_at);
    }

    /** @return array<string, array{0: UserRole}> */
    public static function nonAdminRoles(): array
    {
        return [
            'planner' => [UserRole::Planner],
            'projectleider' => [UserRole::Projectleider],
            'uitvoerder' => [UserRole::Uitvoerder],
            'vakman' => [UserRole::Vakman],
            'alleen_lezen' => [UserRole::AlleenLezen],
            'aangepast' => [UserRole::Aangepast],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nieuwe collega',
            'email' => 'collega@niconvloeren.nl',
            'password' => 'wachtwoord123',
            'password_confirmation' => 'wachtwoord123',
            'role' => UserRole::Planner->value,
            'active' => '1',
            'project_access' => 'all',
        ], $overrides);
    }

    private function assertPermissionCheckbox(string $html, Permission $permission, bool $checked): void
    {
        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*\bname="permissions\[\]"[^>]*\bvalue="'.preg_quote($permission->value, '/').'"[^>]*>/',
            $html,
        );
        preg_match(
            '/<input\b[^>]*\bname="permissions\[\]"[^>]*\bvalue="'.preg_quote($permission->value, '/').'"[^>]*>/',
            $html,
            $matches,
        );
        $tag = $matches[0] ?? '';
        if ($checked) {
            $this->assertMatchesRegularExpression('/\bchecked\b/', $tag);
        } else {
            $this->assertDoesNotMatchRegularExpression('/\bchecked\b/', $tag);
        }
    }

    private function makeProject(string $name): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Zwolle',
            'status' => 'gepland',
        ]);
    }
}
