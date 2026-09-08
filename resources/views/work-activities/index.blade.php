@extends('layouts.app')

@section('title', 'Werkzaamheden · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold">Winkelwerkzaamheden</h1>
            <p class="text-sm text-nicon-muted">Soorten werk toevoegen, wijzigen, uitzetten of onder een categorie plaatsen. Nieuwe keuzes verschijnen daarna bij Winkelwerk.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('work-activity-categories.store') }}" class="mt-6 grid gap-3 border border-nicon-line bg-white p-4 sm:grid-cols-4">
        @csrf
        <div class="sm:col-span-2">
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="category_name">Nieuwe categorie</label>
            <input id="category_name" name="name" value="{{ old('name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Horren">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="category_sort">Volgorde</label>
            <input id="category_sort" name="sort_order" type="number" min="0" value="{{ old('sort_order') }}" class="mt-1 w-full border border-nicon-line px-3 py-2">
        </div>
        <div class="flex items-end">
            <button class="bg-nicon-ink px-4 py-2 text-sm text-white">Categorie toevoegen</button>
        </div>
    </form>

    <div class="mt-6 space-y-6">
        @foreach ($categories as $category)
            <section class="border border-nicon-line bg-white">
                <form method="POST" action="{{ route('work-activity-categories.update', $category) }}" class="flex flex-wrap items-end gap-3 border-b border-nicon-line bg-nicon-sand/50 p-4">
                    @csrf
                    @method('PATCH')
                    <div class="min-w-48 grow">
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted">Categorie</label>
                        <input name="name" value="{{ old('name', $category->name) }}" required class="mt-1 w-full border border-nicon-line bg-white px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted">Volgorde</label>
                        <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $category->sort_order) }}" class="mt-1 w-24 border border-nicon-line bg-white px-3 py-2">
                    </div>
                    <label class="flex items-center gap-2 pb-2 text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($category->is_active)>
                        Actief
                    </label>
                    <button class="border border-nicon-line bg-white px-3 py-2 text-sm">Opslaan</button>
                </form>

                <table class="w-full text-sm">
                    <thead class="text-left text-nicon-muted">
                        <tr>
                            <th class="px-4 py-2 font-normal">Werkzaamheid</th>
                            <th class="px-4 py-2 font-normal">Volgorde</th>
                            <th class="px-4 py-2 font-normal">Status</th>
                            <th class="px-4 py-2 font-normal"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($category->activities as $activity)
                            <tr class="border-t border-nicon-line">
                                <td colspan="4" class="px-4 py-2">
                                    <form method="POST" action="{{ route('work-activities.update', $activity) }}" class="grid items-end gap-3 sm:grid-cols-6">
                                        @csrf
                                        @method('PATCH')
                                        <div class="sm:col-span-2">
                                            <input name="name" value="{{ $activity->name }}" required class="w-full border border-nicon-line px-3 py-2">
                                        </div>
                                        <div>
                                            <select name="work_activity_category_id" class="w-full border border-nicon-line bg-white px-3 py-2">
                                                @foreach ($categories as $option)
                                                    <option value="{{ $option->id }}" @selected($option->id === $activity->work_activity_category_id)>{{ $option->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <input name="sort_order" type="number" min="0" value="{{ $activity->sort_order }}" class="w-full border border-nicon-line px-3 py-2">
                                        </div>
                                        <label class="flex items-center gap-2 pb-2">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="checkbox" name="is_active" value="1" @checked($activity->is_active)>
                                            Actief
                                        </label>
                                        <button class="border border-nicon-line bg-white px-3 py-2">Opslaan</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        <tr class="border-t border-nicon-line bg-nicon-sand/30">
                            <td colspan="4" class="px-4 py-3">
                                <form method="POST" action="{{ route('work-activities.store') }}" class="grid items-end gap-3 sm:grid-cols-4">
                                    @csrf
                                    <input type="hidden" name="work_activity_category_id" value="{{ $category->id }}">
                                    <div class="sm:col-span-2">
                                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted">Nieuwe werkzaamheid in {{ $category->name }}</label>
                                        <input name="name" required class="mt-1 w-full border border-nicon-line bg-white px-3 py-2" placeholder="Horren">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted">Volgorde</label>
                                        <input name="sort_order" type="number" min="0" class="mt-1 w-full border border-nicon-line bg-white px-3 py-2">
                                    </div>
                                    <button class="bg-nicon-orange px-3 py-2 text-white">Toevoegen</button>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        @endforeach
    </div>
@endsection
