<?php

namespace Tests\Unit;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\WorkActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopWorkLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_work_line_uses_product_category_with_all_selected_names(): void
    {
        $project = $this->winkel('Jansen', 'Hengelo', ['screens', 'rolluiken', 'montage']);

        $this->assertSame('Jansen - Hengelo', $project->shopHeadline());
        $this->assertSame('Jansen - Hengelo', $project->displayTitle());
        $this->assertSame('Zonwering · Screens + Rolluiken + Montage', $project->shopWorkLine());
    }

    public function test_shop_work_line_for_flooring_combination(): void
    {
        $project = $this->winkel('De Vries', 'Enschede', ['pvc', 'egaliseren', 'plinten']);

        $this->assertSame('De Vries - Enschede', $project->shopHeadline());
        $this->assertSame('Vloeren · PVC + Egaliseren + Plinten', $project->shopWorkLine());
    }

    public function test_mixed_product_categories_are_joined_in_the_prefix(): void
    {
        $project = $this->winkel('Bakker', 'Almelo', ['pvc', 'screens']);

        $this->assertSame('Vloeren + Zonwering · PVC + Screens', $project->shopWorkLine());
    }

    public function test_only_misc_activities_use_overig_as_prefix(): void
    {
        $project = $this->winkel('Pietersen', 'Goor', ['inmeten', 'service']);

        $this->assertSame('Overig · Inmeten + Service', $project->shopWorkLine());
    }

    /**
     * @param  list<string>  $slugs
     */
    private function winkel(string $customer, string $city, array $slugs): Project
    {
        $owner = Customer::query()->create(['name' => $customer, 'city' => $city]);
        $project = Project::query()->create([
            'project_number' => 'W-'.fake()->unique()->numerify('###'),
            'customer_id' => $owner->id,
            'name' => $customer.' - '.$city,
            'city' => $city,
            'kind' => ProjectKind::Winkel,
            'status' => 'gepland',
        ]);

        $sync = [];
        foreach ($slugs as $index => $slug) {
            $activity = WorkActivity::query()->where('slug', $slug)->firstOrFail();
            $sync[$activity->id] = ['notes' => null, 'sort_order' => $index + 1];
        }
        $project->workActivities()->sync($sync);

        return $project->fresh(['customer', 'workActivities.category']);
    }
}
