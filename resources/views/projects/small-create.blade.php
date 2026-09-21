@extends('layouts.app')

@section('title', 'Klein werk · Nicon Planning')

@section('content')
    @php
        $linked = $selectedType === \App\Enums\SmallWorkType::Extra->value;
    @endphp
    <a href="{{ route('planning') }}" class="text-sm text-nicon-muted">← Planning</a>
    <h1 class="mt-2 text-2xl font-semibold">Klein werk inplannen</h1>
    <p class="mt-1 text-sm text-nicon-muted">Service, extra werk of een losse klus, zonder de volledige projectimport.</p>

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('projects.small.store') }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-4 border border-nicon-line bg-white p-5" data-small-work-form>
        @csrf
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="type">Type</label>
            <select id="type" name="type" required class="mt-1 w-full border border-nicon-line bg-white px-3 py-2" data-small-work-type>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected($selectedType === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>

        <div @class(['hidden' => $linked]) data-small-work-standalone>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Klant</label>
            <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" @required(! $linked)>
        </div>

        <div @class(['hidden' => $linked]) data-small-work-standalone>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="contact_name">Contactpersoon</label>
            <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Naam" autocomplete="name">
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="contact_phone">Telefoon</label>
                    <input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="06 12345678" autocomplete="tel">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="contact_role">Wie is het</label>
                    <select id="contact_role" name="contact_role" class="mt-1 w-full border border-nicon-line bg-white px-3 py-2">
                        <option value="">Kies rol</option>
                        @foreach (\App\Enums\ContactRole::cases() as $role)
                            <option value="{{ $role->value }}" @selected((string) old('contact_role') === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div @class(['hidden' => ! $linked]) data-small-work-linked>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="project_id">Opdrachtnummer / klant</label>
            <select id="project_id" name="project_id" class="mt-1 w-full border border-nicon-line bg-white px-3 py-2" @required($linked)>
                <option value="">Kies opdracht</option>
                @foreach ($parentProjects as $project)
                    @php
                        $optionParts = array_values(array_unique(array_filter([
                            $project->labeledNumbersLine(),
                            $project->customer?->name,
                            $project->displayTitle(),
                        ], fn (?string $part): bool => $part !== null && $part !== '')));
                    @endphp
                    <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>
                        {{ implode(' — ', $optionParts) }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-nicon-muted">Klant en werknummer komen van dit project. Uren blijven bij dit opdrachtnummer.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2" @class(['hidden' => ! $linked]) data-small-work-linked>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="klaar_date">Klaar</label>
                <input id="klaar_date" type="date" name="klaar_date" value="{{ old('klaar_date') }}" class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="description">Korte omschrijving</label>
            <input id="description" name="description" value="{{ old('description') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="plint herstellen">
        </div>

        <div @class(['hidden' => ! $linked]) data-small-work-linked>
            @include('projects.partials.extra-lines', [
                'lines' => $lines,
                'showCompleted' => false,
                'canUpdate' => true,
            ])
        </div>

        <div @class(['hidden' => $linked]) data-small-work-standalone>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="address">Adres</label>
            <input id="address" name="address" value="{{ old('address') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Straat 12">
            <div class="mt-2 grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="postal_code">Postcode</label>
                    <input id="postal_code" name="postal_code" value="{{ old('postal_code') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="7411 HD">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="location">Plaats</label>
                    <input id="location" name="location" value="{{ old('location') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Deventer" @required(! $linked)>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="date">Datum</label>
                <input id="date" type="date" name="date" value="{{ old('date') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="hours">Geplande uren</label>
                <select id="hours" name="hours" required class="mt-1 w-full border border-nicon-line bg-white px-3 py-2">
                    @foreach ($hourOptions as $hours)
                        <option value="{{ $hours }}" @selected((string) old('hours', '4') === (string) $hours)>{{ $hours }}u</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="worker_id">Vakman / team (optioneel)</label>
            <select id="worker_id" name="worker_id" class="mt-1 w-full border border-nicon-line bg-white px-3 py-2">
                <option value="">Later inplannen</option>
                @foreach ($workers as $worker)
                    <option value="{{ $worker->id }}" @selected((string) old('worker_id') === (string) $worker->id)>{{ $worker->planName() }}</option>
                @endforeach
            </select>
        </div>

        <div @class(['hidden' => $linked]) data-small-work-standalone>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="attachments">Tekening</label>
            <input id="attachments" type="file" name="attachments[]" multiple accept="image/*,.pdf,application/pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp" class="mt-1 w-full text-sm">
            <p class="mt-1 text-xs text-nicon-muted">Foto, PDF of tekening. Maximaal {{ $maxFileMegabytes }} MB per bestand.</p>
        </div>

        <div @class(['hidden' => $linked]) data-small-work-standalone>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="work_number">Werknummer (optioneel)</label>
            <input id="work_number" name="work_number" value="{{ old('work_number') }}" class="mt-1 w-full border border-nicon-line px-3 py-2">
        </div>

        <button class="bg-nicon-orange px-5 py-3 font-medium text-white">Inplannen</button>
    </form>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-small-work-form]');
            const typeSelect = form?.querySelector('[data-small-work-type]');
            const toggle = () => {
                const linked = typeSelect?.value === 'extra';
                form?.querySelectorAll('[data-small-work-standalone]').forEach((el) => el.classList.toggle('hidden', linked));
                form?.querySelectorAll('[data-small-work-linked]').forEach((el) => el.classList.toggle('hidden', !linked));
                const customer = form?.querySelector('#customer_name');
                const location = form?.querySelector('#location');
                const project = form?.querySelector('#project_id');
                if (customer) customer.required = !linked;
                if (location) location.required = !linked;
                if (project) project.required = linked;
            };
            typeSelect?.addEventListener('change', toggle);
            toggle();
        });
    </script>
@endpush
