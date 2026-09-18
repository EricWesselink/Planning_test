<?php

use App\Support\ShopWorkCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        ShopWorkCatalog::replaceLegacyPvc();
        ShopWorkCatalog::ensureMissing();
    }

    public function down(): void
    {
        $strokenId = DB::table('work_activities')->where('slug', 'pvc-stroken')->value('id');
        if ($strokenId !== null && ! DB::table('project_work_activities')->where('work_activity_id', $strokenId)->exists()) {
            DB::table('work_activities')->where('id', $strokenId)->delete();
        }

        $banenId = DB::table('work_activities')->where('slug', 'pvc-banen')->value('id');
        if ($banenId !== null && ! DB::table('work_activities')->where('slug', 'pvc')->exists()) {
            DB::table('work_activities')->where('id', $banenId)->update([
                'name' => 'PVC',
                'slug' => 'pvc',
                'updated_at' => now(),
            ]);
            DB::table('work_items')
                ->where('work_activity_id', $banenId)
                ->where('name', 'PVC banen')
                ->update([
                    'name' => 'PVC',
                    'updated_at' => now(),
                ]);
        }
    }
};
