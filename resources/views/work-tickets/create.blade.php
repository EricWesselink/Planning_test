@extends('layouts.app')

@section('title', $kind->label().' maken · Nicon Planning')

@section('content')
    <a href="{{ route('planning') }}" class="text-sm text-nicon-muted">← Planning</a>
    <div class="mt-2">
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Planning</div>
        <h1 class="text-2xl font-semibold">{{ $kind->label() }} maken</h1>
        <p class="text-sm text-nicon-muted">
            {{ $worker->displayName() }}
            · {{ $project->displayTitle() }}
            @if ($project->nawLine()) · {{ $project->nawLine() }} @endif
            · {{ $assignment->dateRangeLabel() }}
        </p>
    </div>

    @if ($existing->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-2 text-sm">
            @foreach ($existing as $ticket)
                <a class="border border-nicon-line bg-white px-3 py-1.5" href="{{ route('work-tickets.show', $ticket) }}">
                    {{ $ticket->kind->label() }} {{ $ticket->number }}
                </a>
            @endforeach
        </div>
    @endif

    @if ($errors->any())
        <ul class="mt-4 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @if ($floors === [] || $workItems === [])
        <p class="mt-6 border border-nicon-line bg-white px-4 py-6 text-sm text-nicon-muted">
            Dit project heeft nog geen Meetstaat-ruimtes of werkzaamheden. Importeer eerst de Meetstaat.
        </p>
    @else
        <form
            method="POST"
            action="{{ route('work-tickets.store', $assignment) }}"
            class="mt-6 space-y-6"
            id="work-ticket-form"
            data-quantities='@json($quantities)'
            data-work-items='@json($workItems)'
            data-external="{{ $isExternal ? '1' : '0' }}"
        >
            @csrf

            <section class="border border-nicon-line bg-white">
                <h2 class="border-b border-nicon-line px-4 py-3 text-sm font-semibold">Verdiepingen en ruimtes</h2>
                <div class="divide-y divide-nicon-line">
                    @foreach ($floors as $floor)
                        @php $floorId = $floor['id']; @endphp
                        <div class="space-y-2 px-4 py-3" data-floor-block data-floor-id="{{ $floorId }}" data-floor-name="{{ $floor['name'] }}">
                            <label class="flex items-center gap-2 text-sm font-medium">
                                <input type="checkbox" name="floors[{{ $floorId }}][included]" value="1" class="work-ticket-floor" @checked(old('floors.'.$floorId.'.included'))>
                                {{ $floor['name'] }}
                            </label>
                            @if ($floorId !== 0)
                                <div class="flex flex-wrap gap-4 text-sm">
                                    <label class="flex items-center gap-2">
                                        <input type="radio" name="floors[{{ $floorId }}][scope]" value="entire" class="work-ticket-scope" @checked(old('floors.'.$floorId.'.scope', 'entire') === 'entire')>
                                        Hele verdieping
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input type="radio" name="floors[{{ $floorId }}][scope]" value="rooms" class="work-ticket-scope" @checked(old('floors.'.$floorId.'.scope') === 'rooms')>
                                        Losse ruimtes
                                    </label>
                                </div>
                            @else
                                <input type="hidden" name="floors[{{ $floorId }}][scope]" value="entire">
                            @endif
                            <div class="flex flex-col gap-1 pl-6 text-sm" data-room-list>
                                @foreach ($floor['areas'] as $area)
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="floors[{{ $floorId }}][area_ids][]" value="{{ $area['id'] }}" class="work-ticket-area" @checked(in_array((string) $area['id'], array_map('strval', old('floors.'.$floorId.'.area_ids', [])), true))>
                                        {{ $area['label'] }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="border border-nicon-line bg-white">
                <h2 class="border-b border-nicon-line px-4 py-3 text-sm font-semibold">Werkzaamheden</h2>
                <div class="flex flex-col gap-2 px-4 py-3 text-sm">
                    @foreach ($workItems as $item)
                        <label class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                name="work_item_ids[]"
                                value="{{ $item['id'] }}"
                                class="work-ticket-work"
                                data-unit="{{ $item['unit_label'] }}"
                                data-price="{{ $item['suggested_price'] ?? '' }}"
                                @checked(in_array((string) $item['id'], array_map('strval', old('work_item_ids', $item['selected'] ? [$item['id']] : [])), true))
                            >
                            {{ $item['name'] }}
                            <span class="text-nicon-muted">({{ $item['unit_label'] }})</span>
                        </label>
                    @endforeach
                </div>
            </section>

            @if ($documents !== [])
                <section class="border border-nicon-line bg-white">
                    <h2 class="border-b border-nicon-line px-4 py-3 text-sm font-semibold">Tekeningen</h2>
                    <div class="flex flex-col gap-2 px-4 py-3 text-sm">
                        @foreach ($documents as $document)
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    name="document_ids[]"
                                    value="{{ $document['id'] }}"
                                    class="work-ticket-drawing"
                                    data-floor="{{ $document['floor'] ?? '' }}"
                                    @checked(in_array((string) $document['id'], array_map('strval', old('document_ids', [])), true))
                                >
                                {{ $document['name'] }}
                            </label>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($isExternal)
                <section class="border border-nicon-line bg-white">
                    <h2 class="border-b border-nicon-line px-4 py-3 text-sm font-semibold">Afrekening</h2>
                    <div class="flex flex-col gap-2 px-4 py-3 text-sm">
                        @foreach (\App\Enums\WorkTicketBilling::cases() as $method)
                            <label class="flex items-center gap-2">
                                <input type="radio" name="billing_method" value="{{ $method->value }}" class="work-ticket-billing" @checked(old('billing_method', 'unit') === $method->value)>
                                {{ $method->label() }}
                            </label>
                        @endforeach
                        <div class="work-ticket-hourly mt-2 hidden">
                            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="hourly_rate">Uurtarief</label>
                            <div class="mt-1 flex items-center gap-2">
                                <span>€</span>
                                <input id="hourly_rate" type="text" inputmode="decimal" name="hourly_rate" value="{{ old('hourly_rate', $hourlyRate) }}" class="w-28 border border-nicon-line px-2 py-1.5">
                                <span class="text-nicon-muted">/uur — uren later op de bon</span>
                            </div>
                        </div>
                        <div class="work-ticket-fixed mt-2 hidden">
                            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="fixed_price">Vaste prijs</label>
                            <div class="mt-1 flex items-center gap-2">
                                <span>€</span>
                                <input id="fixed_price" type="text" inputmode="decimal" name="fixed_price" value="{{ old('fixed_price') }}" class="w-28 border border-nicon-line px-2 py-1.5">
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            <section class="border border-nicon-line bg-white">
                <h2 class="border-b border-nicon-line px-4 py-3 text-sm font-semibold">Opdracht</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-nicon-muted">
                                <th class="px-4 py-2">Werkzaamheid</th>
                                <th class="px-4 py-2 text-right">Hoeveelheid</th>
                                @if ($isExternal)
                                    <th class="px-4 py-2 text-right work-ticket-price-col">Prijs</th>
                                    <th class="px-4 py-2 text-right work-ticket-price-col">Bedrag</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody id="work-ticket-preview">
                            <tr>
                                <td class="px-4 py-3 text-nicon-muted" colspan="{{ $isExternal ? 4 : 2 }}">Selecteer ruimtes en werkzaamheden.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="notes">Opmerking / instructie</label>
                <textarea id="notes" name="notes" rows="3" class="mt-1 w-full border border-nicon-line px-3 py-2">{{ old('notes') }}</textarea>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="submit" class="bg-nicon-orange px-4 py-2 text-white">{{ $kind->label() }} maken</button>
                <a href="{{ route('planning') }}" class="border border-nicon-line bg-white px-4 py-2">Annuleren</a>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
    @vite(['resources/js/work-tickets.js'])
@endpush
