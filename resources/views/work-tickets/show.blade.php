@extends('layouts.app')

@section('title', $ticket->kind->label().' '.$ticket->number.' · Nicon Planning')

@section('content')
    @php
        $backUrl = auth()->user()?->isVakman()
            ? route('vakman.planning')
            : route('planning');
        $backLabel = auth()->user()?->isVakman() ? 'Mijn planning' : 'Planning';
    @endphp
    <a href="{{ $backUrl }}" class="text-sm text-nicon-muted">← {{ $backLabel }}</a>
    <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">{{ $ticket->kind->label() }}</div>
            <h1 class="text-2xl font-semibold">{{ $ticket->kind->label() }} {{ $ticket->number }}</h1>
            <p class="text-sm text-nicon-muted">{{ $recipient }} · {{ $period }}</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('work-tickets.pdf', $ticket) }}" class="bg-nicon-orange px-3 py-2 text-white">Download PDF</a>
            <button type="button" onclick="window.print()" class="border border-nicon-line bg-white px-3 py-2">Afdrukken</button>
        </div>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="mt-4 text-sm text-nicon-danger">{{ $errors->first() }}</p>
    @endif

    <article class="mt-6 border border-nicon-line bg-white">
        <div class="space-y-3 px-4 py-4 text-sm">
            <p>
                <span class="text-xs uppercase tracking-wide text-nicon-muted">Project</span><br>
                {{ $projectTitle }}
            </p>
            @if ($address)
                <p>
                    <span class="text-xs uppercase tracking-wide text-nicon-muted">Adres</span><br>
                    {{ $address }}
                </p>
            @endif
            <p>
                <span class="text-xs uppercase tracking-wide text-nicon-muted">Periode</span><br>
                {{ $period }}
            </p>
            @if ($floors !== '')
                <p>
                    <span class="text-xs uppercase tracking-wide text-nicon-muted">Verdieping(en)</span><br>
                    {{ $floors }}
                </p>
            @endif
            @if ($rooms !== '')
                <p>
                    <span class="text-xs uppercase tracking-wide text-nicon-muted">Ruimte(n)</span><br>
                    {{ $rooms }}
                </p>
            @endif
            @if ($ticket->notes)
                <p>
                    <span class="text-xs uppercase tracking-wide text-nicon-muted">Opmerking</span><br>
                    {{ $ticket->notes }}
                </p>
            @endif
            @if ($colleagues !== [])
                <p>
                    <span class="text-xs uppercase tracking-wide text-nicon-muted">Ook op het werk</span><br>
                    {{ implode(', ', $colleagues) }}
                </p>
            @endif
            @if ($showPrices && $ticket->billingLabel())
                <p>
                    <span class="text-xs uppercase tracking-wide text-nicon-muted">Afrekening</span><br>
                    {{ $ticket->billingLabel() }}
                </p>
            @endif
        </div>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-t border-nicon-line text-left text-xs uppercase tracking-wide text-nicon-muted">
                    <th class="px-4 py-2">Werkzaamheid</th>
                    <th class="px-4 py-2 text-right">Hoeveelheid</th>
                    @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Unit)
                        <th class="px-4 py-2 text-right">Prijs</th>
                        <th class="px-4 py-2 text-right">Bedrag</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($ticket->lines as $line)
                    <tr class="border-t border-nicon-line">
                        <td class="px-4 py-2">{{ $line->workItem?->name ?? 'Werkzaamheid' }}</td>
                        <td class="px-4 py-2 text-right">{{ \App\Support\Format::qty($line->quantity, 2) }} {{ $line->unit?->label() }}</td>
                        @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Unit)
                            <td class="px-4 py-2 text-right">{{ \App\Support\Format::money($line->unit_price) }}/{{ $line->unit?->label() }}</td>
                            <td class="px-4 py-2 text-right">{{ \App\Support\Format::money($line->amount) }}</td>
                        @endif
                    </tr>
                @endforeach
                @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Unit)
                    <tr class="border-t-2 border-nicon-ink font-semibold">
                        <td class="px-4 py-2" colspan="3">Totaal</td>
                        <td class="px-4 py-2 text-right">{{ \App\Support\Format::money($ticket->totalAmount()) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        @if ($drawings !== [])
            <div class="border-t border-nicon-line px-4 py-3 text-sm">
                <div class="text-xs uppercase tracking-wide text-nicon-muted">Tekening(en)</div>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($ticket->documents as $document)
                        <li>
                            <a class="text-nicon-orange hover:underline" href="{{ route('projects.documents.show', [$ticket->project, $document]) }}">{{ $document->original_filename }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </article>

    @if ($canRecordHours)
        <form method="POST" action="{{ route('work-tickets.hours.update', $ticket) }}" class="mt-6 border border-nicon-line bg-white px-4 py-4">
            @csrf
            @method('PATCH')
            <h2 class="text-sm font-semibold">Bestede uren</h2>
            <p class="mt-1 text-sm text-nicon-muted">Uurtarief {{ \App\Support\Format::money($ticket->hourly_rate) }}/uur. Uren later op deze bon bijwerken.</p>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="worked_hours">Uren</label>
                    <input id="worked_hours" type="text" inputmode="decimal" name="worked_hours" value="{{ old('worked_hours', $ticket->worked_hours) }}" class="mt-1 w-28 border border-nicon-line px-2 py-1.5">
                </div>
                <button type="submit" class="bg-nicon-orange px-4 py-2 text-sm text-white">Opslaan</button>
                @if ($showPrices && $ticket->worked_hours !== null)
                    <p class="text-sm">Totaal {{ \App\Support\Format::money($ticket->totalAmount()) }}</p>
                @endif
            </div>
        </form>
    @elseif ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Hourly)
        <p class="mt-4 text-sm text-nicon-muted">
            Uurtarief {{ \App\Support\Format::money($ticket->hourly_rate) }}/uur
            @if ($ticket->worked_hours !== null)
                · {{ \App\Support\Format::hours($ticket->worked_hours) }}
                · {{ \App\Support\Format::money($ticket->totalAmount()) }}
            @else
                · uren nog niet geregistreerd
            @endif
        </p>
    @elseif ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Fixed)
        <p class="mt-4 text-sm">Vaste prijs {{ \App\Support\Format::money($ticket->fixed_price) }}</p>
    @endif
@endsection
