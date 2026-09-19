<?php

namespace App\Http\Controllers;

use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use App\Support\ShopWorkCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkActivityController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->canManageCatalog() || auth()->user()?->canViewCatalog(), 403);

        return view('work-activities.index', [
            'categories' => WorkActivityCategory::query()
                ->ordered()
                ->with(['activities' => fn ($query) => $query->ordered()])
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        $data = $request->validate($this->rules(), $this->messages());

        WorkActivity::query()->create([
            'work_activity_category_id' => $data['work_activity_category_id'],
            'name' => $data['name'],
            'slug' => ShopWorkCatalog::uniqueSlug($data['name'], 'work_activities'),
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder((int) $data['work_activity_category_id']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', 'Werkzaamheid toegevoegd.');
    }

    public function update(Request $request, WorkActivity $activity): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        $data = $request->validate($this->rules(), $this->messages());

        $activity->update([
            'work_activity_category_id' => $data['work_activity_category_id'],
            'name' => $data['name'],
            'slug' => ShopWorkCatalog::uniqueSlug($data['name'], 'work_activities', $activity->id),
            'sort_order' => $data['sort_order'] ?? $activity->sort_order,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Werkzaamheid opgeslagen.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'work_activity_category_id' => ['required', 'integer', Rule::exists('work_activity_categories', 'id')],
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
            'work_activity_category_id.required' => 'Kies een categorie.',
            'name.required' => 'Vul een naam in.',
        ];
    }

    private function nextSortOrder(int $categoryId): int
    {
        return (int) WorkActivity::query()
            ->where('work_activity_category_id', $categoryId)
            ->max('sort_order') + 1;
    }
}
