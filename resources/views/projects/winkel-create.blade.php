@extends('layouts.app')

@section('title', 'Nieuw Winkelwerk · Nicon Planning')

@section('content')
    <a href="{{ route('projects.create') }}" class="text-sm text-nicon-muted">← Nieuw project</a>
    <h1 class="mt-2 text-2xl font-semibold">Nieuw Winkelwerk</h1>
    <p class="mt-1 text-sm text-nicon-muted">Vloeren, raambekleding, zonwering of een combinatie. Blijft één werk in dezelfde planning.</p>

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('projects.winkel.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4 border border-nicon-line bg-white p-4">
        @csrf
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="grid grid-cols-1 gap-x-3 gap-y-2 sm:grid-cols-2">
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Klant</label>
                    <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" required class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="Jansen">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="city">Plaats</label>
                    <input id="city" name="city" value="{{ old('city') }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="Hengelo">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="address">Adres</label>
                    <input id="address" name="address" value="{{ old('address') }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="Straat 12">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="postal_code">Postcode</label>
                    <input id="postal_code" name="postal_code" value="{{ old('postal_code') }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="7551 AA">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="contact_phone">Telefoon</label>
                    <input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone') }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="06 12345678" autocomplete="tel">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="contact_email">E-mail</label>
                    <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="jansen@example.nl" autocomplete="email">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="basis_uurtarief">Uurtarief (€) <span class="font-normal normal-case tracking-normal">Standaard €48/u</span></label>
                    <input id="basis_uurtarief" name="basis_uurtarief" value="{{ old('basis_uurtarief', $hourlyRate) }}" inputmode="decimal" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="48" title="Aanpasbaar. Begrote uren × dit tarief.">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="order_amount">Orderbedrag excl. btw (€)</label>
                    <input id="order_amount" name="order_amount" value="{{ old('order_amount') }}" inputmode="decimal" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="8.500,00" title="Het totale verkoopbedrag van dit winkelwerk, exclusief btw.">
                </div>
            </div>
            <div class="flex flex-col gap-2">
                <div class="flex min-h-0 grow flex-col">
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="work_description">Omschrijving werkzaamheden</label>
                    <textarea id="work_description" name="work_description" rows="3" class="mt-1 min-h-[4.5rem] w-full grow border border-nicon-line px-2 py-1.5" placeholder="Wat er precies gedaan moet worden">{{ old('work_description') }}</textarea>
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="attachments">Bijlagen</label>
                    <input id="attachments" type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/jpeg,image/png,image/webp,image/gif,application/pdf" class="mt-1 w-full text-sm">
                    <p class="mt-1 text-xs text-nicon-muted">Meerdere foto’s, PDF’s of tekeningen. Maximaal {{ $maxFileMegabytes }} MB per bestand.</p>
                </div>
                @include('projects.partials.planning-weeks', ['idPrefix' => 'winkel-', 'compact' => true])
                @include('projects.partials.preferred-worker', [
                    'preferredWorkers' => $preferredWorkers,
                    'selectedWorkerId' => $selectedWorkerId ?? null,
                    'multiplePreferredWorkers' => $multiplePreferredWorkers ?? false,
                    'project' => $project ?? null,
                ])
            </div>
        </div>

        @include('projects.partials.shop-activities', [
            'categories' => $categories,
            'selectedIds' => $selectedIds,
            'activityNotes' => $activityNotes,
            'activityQuantities' => $activityQuantities,
            'activityUnits' => $activityUnits,
            'activityHours' => $activityHours,
            'hourlyRate' => $hourlyRate,
        ])

        @include('projects.partials.measurement-form')

        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Winkelwerk aanmaken</button>
    </form>
@endsection

@push('scripts')
    @vite(['resources/js/winkel-preferred-worker.js', 'resources/js/measurement-form.js'])
@endpush
