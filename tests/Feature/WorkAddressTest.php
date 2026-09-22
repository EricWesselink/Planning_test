<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\SmallWorkType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Support\WorkAddress;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_merges_existing_parts_and_keeps_every_filled_address(): void
    {
        $customer = Customer::query()->create(['name' => 'Hegeman']);
        $fullId = $this->insertProject($customer->id, [
            'project_number' => '260900001',
            'address' => 'Willem Schuylenburglaan 40-9',
            'postal_code' => '3571 SJ',
            'city' => 'Utrecht',
        ]);
        $streetAndCityId = $this->insertProject($customer->id, [
            'project_number' => '260900002',
            'address' => 'Kerkstraat 12',
            'city' => 'Utrecht',
        ]);
        $streetId = $this->insertProject($customer->id, [
            'project_number' => '260900003',
            'address' => 'Kerkstraat 12',
        ]);
        $postalId = $this->insertProject($customer->id, [
            'project_number' => '260900004',
            'postal_code' => '3571 SJ',
            'city' => 'Utrecht',
        ]);
        $keptId = $this->insertProject($customer->id, [
            'project_number' => '260900005',
            'address' => 'Oude straat 1',
            'city' => 'Zwolle',
            'work_address' => 'Bewaar deze regel',
        ]);
        $emptyId = $this->insertProject($customer->id, [
            'project_number' => '260900006',
        ]);

        WorkAddress::backfill();
        WorkAddress::backfill();

        $full = DB::table('projects')->find($fullId);
        $this->assertSame('Willem Schuylenburglaan 40-9', $full->address);
        $this->assertSame('3571 SJ', $full->postal_code);
        $this->assertSame('Utrecht', $full->city);
        $this->assertSame('Willem Schuylenburglaan 40-9, 3571 SJ Utrecht', $full->work_address);

        $streetAndCity = DB::table('projects')->find($streetAndCityId);
        $this->assertSame('Kerkstraat 12', $streetAndCity->address);
        $this->assertSame('Utrecht', $streetAndCity->city);
        $this->assertNull($streetAndCity->postal_code);
        $this->assertSame('Kerkstraat 12, Utrecht', $streetAndCity->work_address);

        $this->assertSame('Kerkstraat 12', DB::table('projects')->find($streetId)->work_address);
        $this->assertSame('Kerkstraat 12', DB::table('projects')->find($streetId)->address);
        $this->assertSame('3571 SJ Utrecht', DB::table('projects')->find($postalId)->work_address);
        $this->assertSame('3571 SJ', DB::table('projects')->find($postalId)->postal_code);
        $this->assertSame('Utrecht', DB::table('projects')->find($postalId)->city);

        $kept = DB::table('projects')->find($keptId);
        $this->assertSame('Bewaar deze regel', $kept->work_address);
        $this->assertSame('Oude straat 1', $kept->address);
        $this->assertSame('Zwolle', $kept->city);

        $this->assertNull(DB::table('projects')->find($emptyId)->work_address);
        $this->assertTrue(Schema::hasColumns('projects', ['address', 'postal_code', 'city', 'work_address']));
    }

    public function test_missing_work_address_column_is_added_without_changing_existing_project_rows(): void
    {
        $customer = Customer::query()->create(['name' => 'Hegeman']);
        $id = $this->insertProject($customer->id, [
            'project_number' => '260900010',
            'address' => 'Kerkstraat 12',
            'city' => 'Utrecht',
        ]);

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('work_address');
        });

        WorkAddress::ensureColumn();

        $row = DB::table('projects')->find($id);
        $this->assertTrue(Schema::hasColumn('projects', 'work_address'));
        $this->assertSame('Bestaand werk', $row->name);
        $this->assertSame('Kerkstraat 12', $row->address);
        $this->assertSame('Utrecht', $row->city);
        $this->assertSame('Kerkstraat 12, Utrecht', $row->work_address);

        DB::table('projects')->where('id', $id)->update(['work_address' => 'Handmatig aangepast']);
        WorkAddress::ensureColumn();

        $again = DB::table('projects')->find($id);
        $this->assertSame('Handmatig aangepast', $again->work_address);
        $this->assertSame('Kerkstraat 12', $again->address);
        $this->assertSame('Utrecht', $again->city);
    }

    public function test_pasted_work_address_is_stored_and_shown_as_one_line(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $line = 'Willem Schuylenburglaan 40-9, 3571 SJ Utrecht';

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'work_address' => $line,
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('Willem Schuylenburglaan 40-9', $project->address);
        $this->assertSame('3571 SJ', $project->postal_code);
        $this->assertSame('Utrecht', $project->city);
        $this->assertSame($line, $project->work_address);
        $this->assertSame($line, $project->nawLine());

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('name="work_address"', false)
            ->assertSee('value="'.$line.'"', false)
            ->assertDontSee('name="postal_code"', false);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('value="'.$line.'"', false)
            ->assertDontSee('name="postal_code"', false);
    }

    public function test_address_without_a_postal_code_keeps_the_place(): void
    {
        $user = User::factory()->create();
        $project = $this->project();

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'work_address' => 'Kerkstraat 12, Utrecht',
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertSame('Kerkstraat 12', $project->address);
        $this->assertNull($project->postal_code);
        $this->assertSame('Utrecht', $project->city);
        $this->assertSame('Kerkstraat 12, Utrecht', $project->work_address);
    }

    public function test_resaving_an_existing_address_does_not_drop_parts(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $project->update([
            'address' => null,
            'postal_code' => null,
            'city' => 'Deventer',
        ]);

        $this->actingAs($user)
            ->patch(route('projects.update', $project), [
                'work_address' => 'Deventer',
            ])
            ->assertRedirect();

        $project->refresh();
        $this->assertNull($project->address);
        $this->assertSame('Deventer', $project->city);
        $this->assertSame('Deventer', $project->work_address);
    }

    public function test_search_matches_street_postal_code_place_and_the_full_line(): void
    {
        $project = $this->project();
        $project->update([
            'address' => 'Willem Schuylenburglaan 40-9',
            'postal_code' => '3571 SJ',
            'city' => 'Utrecht',
        ]);

        foreach ([
            'Willem Schuylenburglaan',
            '3571 SJ',
            'Utrecht',
            'Willem Schuylenburglaan 40-9, 3571 SJ Utrecht',
        ] as $term) {
            $this->assertTrue(
                Project::query()->matchingSearch($term)->whereKey($project->id)->exists(),
                $term,
            );
        }
    }

    public function test_small_work_and_winkelwerk_accept_one_pasted_address(): void
    {
        $user = User::factory()->create();
        $activityId = WorkActivity::query()->value('id');
        $line = 'Willem Schuylenburglaan 40-9, 3571 SJ Utrecht';

        $this->actingAs($user)
            ->post(route('projects.small.store'), [
                'type' => SmallWorkType::Service->value,
                'customer_name' => 'Jansen',
                'description' => 'plint herstellen',
                'work_address' => $line,
                'date' => '2026-09-21',
                'hours' => 4,
            ])
            ->assertRedirect();

        $service = Project::query()->where('kind', 'service')->first();
        $this->assertNotNull($service);
        $this->assertSame('Willem Schuylenburglaan 40-9', $service->address);
        $this->assertSame('3571 SJ', $service->postal_code);
        $this->assertSame('Utrecht', $service->city);
        $this->assertSame($line, $service->work_address);

        $this->actingAs($user)
            ->post(route('projects.winkel.store'), [
                'customer_name' => 'Jansen',
                'work_address' => $line,
                'work_activity_ids' => [$activityId],
            ])
            ->assertRedirect();

        $shop = Project::query()->where('kind', 'winkel')->first();
        $this->assertNotNull($shop);
        $this->assertSame('Utrecht', $shop->city);
        $this->assertSame($line, $shop->work_address);
        $this->assertSame('Jansen - Utrecht', $shop->name);
    }

    public function test_work_address_forms_use_one_field(): void
    {
        $user = User::factory()->create();
        $project = $this->project();

        foreach ([
            route('projects.create'),
            route('projects.small.create'),
            route('projects.winkel.create'),
            route('projects.show', $project),
        ] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertSee('name="work_address"', false)
                ->assertSee('Willem Schuylenburglaan 40-9, 3571 SJ Utrecht', false)
                ->assertDontSee('name="postal_code"', false);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertProject(int $customerId, array $attributes): int
    {
        return DB::table('projects')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'name' => 'Bestaand werk',
            'status' => ProjectStatus::Gepland->value,
            'kind' => 'project',
            'work_address' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function project(): Project
    {
        $customer = Customer::query()->create(['name' => 'Hegeman']);

        return Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen',
            'status' => ProjectStatus::Gepland,
        ]);
    }
}
