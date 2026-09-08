<?php

namespace App\Http\Controllers;

use App\Models\WorkActivityCategory;
use App\Support\ShopWorkCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkActivityCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        $data = $request->validate($this->rules(), $this->messages());

        WorkActivityCategory::query()->create([
            'name' => $data['name'],
            'slug' => ShopWorkCatalog::uniqueSlug($data['name'], 'work_activity_categories'),
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', 'Categorie toegevoegd.');
    }

    public function update(Request $request, WorkActivityCategory $category): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        $data = $request->validate($this->rules(), $this->messages());

        $category->update([
            'name' => $data['name'],
            'slug' => ShopWorkCatalog::uniqueSlug($data['name'], 'work_activity_categories', $category->id),
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Categorie opgeslagen.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Vul een categorienaam in.',
        ];
    }

    private function nextSortOrder(): int
    {
        return (int) WorkActivityCategory::query()->max('sort_order') + 1;
    }
}
