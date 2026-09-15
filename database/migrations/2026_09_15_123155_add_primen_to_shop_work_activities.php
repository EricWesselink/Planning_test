<?php

use App\Support\ShopWorkCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        ShopWorkCatalog::ensureMissing();
    }

    public function down(): void
    {
        $id = DB::table('work_activities')->where('slug', 'primen')->value('id');

        if ($id === null) {
            return;
        }

        if (DB::table('project_work_activities')->where('work_activity_id', $id)->exists()) {
            return;
        }

        DB::table('work_activities')->where('id', $id)->delete();
    }
};
