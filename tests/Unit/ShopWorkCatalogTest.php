<?php

namespace Tests\Unit;

use App\Enums\ProjectKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\WorkActivity;
use App\Support\ShopWorkCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopWorkCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_missing_adds_primen_before_egaliseren_without_duplicating_it(): void
    {
        $primen = WorkActivity::query()->where('slug', 'primen')->first();
        $this->assertNotNull($primen);
        $primen->delete();

        $egaliseren = WorkActivity::query()->where('slug', 'egaliseren')->firstOrFail();
        $egaliseren->update(['sort_order' => 6]);

        ShopWorkCatalog::ensureMissing();

        $primen = WorkActivity::query()->where('slug', 'primen')->first();
        $egaliseren = $egaliseren->fresh();

        $this->assertNotNull($primen);
        $this->assertSame('Primen', $primen->name);
        $this->assertSame($egaliseren->work_activity_category_id, $primen->work_activity_category_id);
        $this->assertSame(6, $primen->sort_order);
        $this->assertSame(7, $egaliseren->sort_order);
        $this->assertSame(1, WorkActivity::query()->where('slug', 'egaliseren')->count());
        $this->assertSame(1, WorkActivity::query()->where('slug', 'primen')->count());
    }

    public function test_ensure_missing_adds_pvc_stroken_after_pvc_banen_without_duplicating_it(): void
    {
        $stroken = WorkActivity::query()->where('slug', 'pvc-stroken')->first();
        $this->assertNotNull($stroken);
        $stroken->delete();

        $banen = WorkActivity::query()->where('slug', 'pvc-banen')->firstOrFail();
        $banen->update(['sort_order' => 1]);
        $marmoleum = WorkActivity::query()->where('slug', 'marmoleum')->firstOrFail();
        $marmoleum->update(['sort_order' => 2]);

        ShopWorkCatalog::ensureMissing();

        $stroken = WorkActivity::query()->where('slug', 'pvc-stroken')->first();
        $marmoleum = $marmoleum->fresh();

        $this->assertNotNull($stroken);
        $this->assertSame('PVC stroken', $stroken->name);
        $this->assertSame($banen->work_activity_category_id, $stroken->work_activity_category_id);
        $this->assertSame(2, $stroken->sort_order);
        $this->assertSame(3, $marmoleum->sort_order);
        $this->assertSame(1, WorkActivity::query()->where('slug', 'pvc-banen')->count());
        $this->assertSame(1, WorkActivity::query()->where('slug', 'pvc-stroken')->count());
        $this->assertSame(0, WorkActivity::query()->where('slug', 'pvc')->count());
    }

    public function test_ensure_missing_adds_werkopname_after_inmeten_without_duplicating_it(): void
    {
        $werkopname = WorkActivity::query()->where('slug', 'werkopname')->first();
        $this->assertNotNull($werkopname);
        $werkopname->delete();

        $inmeten = WorkActivity::query()->where('slug', 'inmeten')->firstOrFail();
        $inmeten->update(['sort_order' => 1]);
        $montage = WorkActivity::query()->where('slug', 'montage')->firstOrFail();
        $montage->update(['sort_order' => 2]);

        ShopWorkCatalog::ensureMissing();

        $werkopname = WorkActivity::query()->where('slug', 'werkopname')->first();
        $montage = $montage->fresh();

        $this->assertNotNull($werkopname);
        $this->assertSame('Werkopname', $werkopname->name);
        $this->assertSame($inmeten->work_activity_category_id, $werkopname->work_activity_category_id);
        $this->assertSame(2, $werkopname->sort_order);
        $this->assertSame(3, $montage->sort_order);
        $this->assertSame(1, WorkActivity::query()->where('slug', 'inmeten')->count());
        $this->assertSame(1, WorkActivity::query()->where('slug', 'werkopname')->count());
    }

    public function test_replace_legacy_pvc_renames_existing_activity_and_work_item(): void
    {
        $banen = WorkActivity::query()->where('slug', 'pvc-banen')->firstOrFail();
        $stroken = WorkActivity::query()->where('slug', 'pvc-stroken')->firstOrFail();
        $categoryId = $banen->work_activity_category_id;
        $sortOrder = $banen->sort_order;
        $stroken->delete();
        $banen->delete();

        $legacy = WorkActivity::query()->create([
            'work_activity_category_id' => $categoryId,
            'name' => 'PVC',
            'slug' => 'pvc',
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Jansen']);
        $project = Project::query()->create([
            'project_number' => 'W-001',
            'customer_id' => $customer->id,
            'name' => 'Jansen - Hengelo',
            'kind' => ProjectKind::Winkel,
            'status' => 'gepland',
        ]);
        $item = $project->workItems()->create([
            'work_activity_id' => $legacy->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 40,
            'status' => 'gepland',
        ]);

        ShopWorkCatalog::replaceLegacyPvc();
        ShopWorkCatalog::ensureMissing();

        $legacy = $legacy->fresh();
        $item = $item->fresh();
        $stroken = WorkActivity::query()->where('slug', 'pvc-stroken')->first();

        $this->assertSame('PVC banen', $legacy?->name);
        $this->assertSame('pvc-banen', $legacy?->slug);
        $this->assertSame('PVC banen', $item?->name);
        $this->assertNotNull($stroken);
        $this->assertSame(0, WorkActivity::query()->where('slug', 'pvc')->count());
    }
}
