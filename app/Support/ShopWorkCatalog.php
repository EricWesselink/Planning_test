<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopWorkCatalog
{
    /**
     * @return list<array{name: string, slug: string, activities: list<array{name: string, slug: string}>}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Vloeren',
                'slug' => 'vloeren',
                'activities' => [
                    ['name' => 'PVC banen', 'slug' => 'pvc-banen'],
                    ['name' => 'PVC stroken', 'slug' => 'pvc-stroken'],
                    ['name' => 'Marmoleum', 'slug' => 'marmoleum'],
                    ['name' => 'Tapijt', 'slug' => 'tapijt'],
                    ['name' => 'Tapijttegels', 'slug' => 'tapijttegels'],
                    ['name' => 'Primen', 'slug' => 'primen'],
                    ['name' => 'Egaliseren', 'slug' => 'egaliseren'],
                    ['name' => 'Plinten', 'slug' => 'plinten'],
                    ['name' => 'Reparatie / herstel', 'slug' => 'reparatie-herstel'],
                    ['name' => 'Overig vloerwerk', 'slug' => 'overig-vloerwerk'],
                ],
            ],
            [
                'name' => 'Raambekleding',
                'slug' => 'raambekleding',
                'activities' => [
                    ['name' => 'Gordijnen', 'slug' => 'gordijnen'],
                    ['name' => 'Vitrage', 'slug' => 'vitrage'],
                    ['name' => 'Inbetweens', 'slug' => 'inbetweens'],
                    ['name' => 'Rolgordijnen', 'slug' => 'rolgordijnen'],
                    ['name' => 'Duo rolgordijnen', 'slug' => 'duo-rolgordijnen'],
                    ['name' => 'Plisségordijnen', 'slug' => 'plissegordijnen'],
                    ['name' => 'Jaloezieën', 'slug' => 'jaloezieen'],
                    ['name' => 'Lamellen', 'slug' => 'lamellen'],
                    ['name' => 'Overige raambekleding', 'slug' => 'overige-raambekleding'],
                ],
            ],
            [
                'name' => 'Zonwering',
                'slug' => 'zonwering',
                'activities' => [
                    ['name' => 'Screens', 'slug' => 'screens'],
                    ['name' => 'Rolluiken', 'slug' => 'rolluiken'],
                    ['name' => 'Uitvalscherm', 'slug' => 'uitvalscherm'],
                    ['name' => 'Knikarmscherm', 'slug' => 'knikarmscherm'],
                    ['name' => 'Markies', 'slug' => 'markies'],
                    ['name' => 'Binnenzonwering', 'slug' => 'binnenzonwering'],
                    ['name' => 'Overige zonwering', 'slug' => 'overige-zonwering'],
                ],
            ],
            [
                'name' => 'Overig',
                'slug' => 'overig',
                'activities' => [
                    ['name' => 'Inmeten', 'slug' => 'inmeten'],
                    ['name' => 'Montage', 'slug' => 'montage'],
                    ['name' => 'Reparatie', 'slug' => 'reparatie'],
                    ['name' => 'Service', 'slug' => 'service'],
                    ['name' => 'Anders', 'slug' => 'anders'],
                ],
            ],
        ];
    }

    public static function seed(): void
    {
        $now = now();

        foreach (self::defaults() as $categoryIndex => $category) {
            $categoryId = DB::table('work_activity_categories')->insertGetId([
                'name' => $category['name'],
                'slug' => $category['slug'],
                'sort_order' => $categoryIndex + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($category['activities'] as $activityIndex => $activity) {
                DB::table('work_activities')->insert([
                    'work_activity_category_id' => $categoryId,
                    'name' => $activity['name'],
                    'slug' => $activity['slug'],
                    'sort_order' => $activityIndex + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public static function replaceLegacyPvc(): void
    {
        $now = now();
        $pvcId = DB::table('work_activities')->where('slug', 'pvc')->value('id');
        $banenExists = DB::table('work_activities')->where('slug', 'pvc-banen')->exists();

        if ($pvcId === null || $banenExists) {
            return;
        }

        DB::table('work_activities')->where('id', $pvcId)->update([
            'name' => 'PVC banen',
            'slug' => 'pvc-banen',
            'updated_at' => $now,
        ]);
        DB::table('work_items')
            ->where('work_activity_id', $pvcId)
            ->where('name', 'PVC')
            ->update([
                'name' => 'PVC banen',
                'updated_at' => $now,
            ]);
    }

    public static function ensureMissing(): void
    {
        $now = now();

        foreach (self::defaults() as $categoryIndex => $category) {
            $categoryId = DB::table('work_activity_categories')->where('slug', $category['slug'])->value('id');

            if ($categoryId === null) {
                $categoryId = DB::table('work_activity_categories')->insertGetId([
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'sort_order' => $categoryIndex + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($category['activities'] as $activityIndex => $activity) {
                if (DB::table('work_activities')->where('slug', $activity['slug'])->exists()) {
                    continue;
                }

                $sortOrder = $activityIndex + 1;
                DB::table('work_activities')
                    ->where('work_activity_category_id', $categoryId)
                    ->where('sort_order', '>=', $sortOrder)
                    ->increment('sort_order');

                DB::table('work_activities')->insert([
                    'work_activity_category_id' => $categoryId,
                    'name' => $activity['name'],
                    'slug' => $activity['slug'],
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public static function uniqueSlug(string $name, string $table, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'onderdeel';
        $slug = $base;
        $suffix = 2;

        while (DB::table($table)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
