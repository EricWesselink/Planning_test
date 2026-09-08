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
                    ['name' => 'PVC', 'slug' => 'pvc'],
                    ['name' => 'Marmoleum', 'slug' => 'marmoleum'],
                    ['name' => 'Tapijt', 'slug' => 'tapijt'],
                    ['name' => 'Tapijttegels', 'slug' => 'tapijttegels'],
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
