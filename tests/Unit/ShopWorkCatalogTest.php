<?php

namespace Tests\Unit;

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
        $egaliseren->update(['sort_order' => 5]);

        ShopWorkCatalog::ensureMissing();

        $primen = WorkActivity::query()->where('slug', 'primen')->first();
        $egaliseren = $egaliseren->fresh();

        $this->assertNotNull($primen);
        $this->assertSame('Primen', $primen->name);
        $this->assertSame($egaliseren->work_activity_category_id, $primen->work_activity_category_id);
        $this->assertSame(5, $primen->sort_order);
        $this->assertSame(6, $egaliseren->sort_order);
        $this->assertSame(1, WorkActivity::query()->where('slug', 'egaliseren')->count());
        $this->assertSame(1, WorkActivity::query()->where('slug', 'primen')->count());
    }
}
