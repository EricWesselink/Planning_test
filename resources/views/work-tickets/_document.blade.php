@php
    $isPdf = $isPdf ?? false;
    $logoSrc = $isPdf ? ($logo ?? null) : ($logoUrl ?? $logo ?? null);
    $kindLabel = $kindLabel ?? '';
    $issuedOn = $issuedOn ?? '';
    $notesText = $notesText ?? trim((string) ($ticket?->notes ?? ''));
    $showPrices = $showPrices ?? false;
    $billing = $ticket?->billing_method;
    $showUnitPrices = $showPrices && ($billing === \App\Enums\WorkTicketBilling::Unit || ($priceMode ?? null) === 'unit');
    $rows = $rows ?? ($ticket?->lines ?? collect());
    $drawingItems = $drawingItems ?? ($drawingEmbeds ?? []);
    $floorLayers = $floorLayers ?? [];
    $drawingUrl = $drawingUrl ?? null;
    $drawingIsPdf = $drawingIsPdf ?? false;
    $drawingIsImage = $drawingIsImage ?? false;
    $drawingName = $drawingName ?? null;
    $total = $total ?? $ticket?->totalAmount();
    $colleagues = $colleagues ?? [];
    $number = $number ?? '';
    $drawingRender = $drawingRender ?? 'image';
    $companyPlace = trim(implode(' ', array_filter([$companyPostalCode ?? null, $companyCity ?? null])));
    $companyAddressLine = $companyAddressLine ?? trim(implode(', ', array_filter([$companyAddress ?? null, $companyPlace])));
    $whoHeading = $whoHeading ?? 'Opdrachtnemer';
    $whenHeading = $whenHeading ?? 'Planning';
    $recipientCompact = $recipientCompact ?? false;
    $pdfDrawingLayers = [];
    if ($isPdf) {
        foreach ($floorLayers as $layer) {
            $layerPage = (int) ($layer['page'] ?? 0);
            $layerImage = $layer['image'] ?? null;
            if ($layerPage > 0 && ($drawingRender === 'browser' || filled($layerImage))) {
                $pdfDrawingLayers[] = $layer;
            }
        }
    }
    $pdfDrawingItems = [];
    if ($isPdf && $pdfDrawingLayers === []) {
        foreach ($drawingItems as $item) {
            if (is_array($item) && filled($item['path'] ?? null)) {
                $pdfDrawingItems[] = $item;
            }
        }
    }
@endphp
<div class="ticket-page">
    <table class="brand">
        <tr>
            <td class="logo">
                @if ($logoSrc)
                    <img src="{{ $logoSrc }}" alt="{{ $companyName }}">
                @endif
            </td>
            <td>
                <div class="brand-name">{{ $companyName }}</div>
                <div class="doc-title">{{ $documentTitle }}</div>
                <div class="doc-meta">
                    {{ $kindLabel }} {{ $number }}
                    @if ($issuedOn !== '')
                        · {{ $issuedOn }}
                    @endif
                </div>
            </td>
            <td class="brand-side">
                <strong>{{ $companyName }}</strong><br>
                @if ($companyAddressLine !== '')
                    {{ $companyAddressLine }}<br>
                @endif
                {{ $companyEmail }} · {{ $companyPhone }}
            </td>
        </tr>
    </table>

    <table class="blocks">
        <tr>
            <td>
                <div class="block-title">Project</div>
                <p><strong>{{ $projectTitle }}</strong></p>
                @if ($projectNumber)
                    <p>Projectnr. {{ $projectNumber }}</p>
                @endif
                @if ($workNumber !== '')
                    <p>Werknummer {{ $workNumber }}</p>
                @endif
                @if ($address)
                    <p>Werkadres {{ $address }}</p>
                @endif
                @if (filled($contactPhone ?? null))
                    <p>Tel. {{ $contactPhone }}</p>
                @endif
                @if (filled($contactEmail ?? null))
                    <p>{{ $contactEmail }}</p>
                @endif
            </td>
            <td>
                <div class="block-title">{{ $whoHeading }}</div>
                <p @class(['crew-line' => $recipientCompact])>
                    @if ($recipientCompact)
                        {{ $recipient }}
                    @else
                        <strong>{{ $recipient }}</strong>
                    @endif
                </p>
                @if ($recipientKind)
                    <p @class(['crew-line' => $recipientCompact])>{{ $recipientKind }}</p>
                @endif
                @if (filled($period ?? null))
                    <div class="block-title" style="margin-top:8px">{{ $whenHeading }}</div>
                    <p @class(['crew-line' => $recipientCompact])>{{ $period }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">Werkopdracht</div>
        @if ($floors !== '')
            <p><strong>Verdieping:</strong> {{ $floors }}</p>
        @endif
        @if ($rooms !== '' && $rooms !== 'Hele verdieping')
            <p><strong>Ruimtes:</strong> {{ $rooms }}</p>
        @endif

        @foreach ($floorLayers as $layer)
            <div class="floor-layer">
                <p><strong>{{ $layer['name'] }}</strong></p>
                @php
                    $layerPage = (int) ($layer['page'] ?? 0);
                    $showMap = ! $isPdf && $layerPage > 0 && filled($drawingUrl) && ($drawingIsPdf || $drawingIsImage);
                @endphp
                @if ($showMap)
                    <div class="map" data-page="{{ $layerPage }}">
                        @if ($drawingIsImage)
                            <img class="map-drawing" src="{{ $drawingUrl }}" alt="">
                        @elseif ($drawingIsPdf)
                            <p class="map-loading">Tekening laden…</p>
                        @endif
                        @foreach ($layer['pins'] ?? [] as $pin)
                            <span class="room-pin" style="left: {{ $pin['x'] * 100 }}%; top: {{ $pin['y'] * 100 }}%;">{{ $pin['label'] }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
        @if (! $isPdf && $floorLayers !== [] && filled($drawingName))
            <div class="drawing-name">{{ $drawingName }}</div>
        @endif

        <table class="lines">
            <thead>
                <tr>
                    <th>Werkzaamheid</th>
                    <th class="num">Hoeveelheid</th>
                    @if ($showUnitPrices)
                        <th class="num">Prijs</th>
                        <th class="num">Bedrag</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $title = is_array($row) ? ($row['title'] ?? 'Werkzaamheid') : ($row->workItem?->name ?? $row->workItem?->planningTitle() ?? 'Werkzaamheid');
                        $qty = is_array($row) ? ($row['quantity'] ?? '') : \App\Support\Format::qty($row->quantity, 2);
                        $unit = is_array($row) ? ($row['unit'] ?? '') : ($row->unit?->label() ?? '');
                        $priceLabel = is_array($row)
                            ? ($row['price_label'] ?? null)
                            : ($row->unit_price !== null ? \App\Support\Format::money($row->unit_price).' / '.$unit : null);
                        $amount = is_array($row) ? ($row['amount'] ?? null) : $row->amount;
                    @endphp
                    <tr>
                        <td>{{ $title }}</td>
                        <td class="num">{{ $qty }} {{ $unit }}</td>
                        @if ($showUnitPrices)
                            <td class="num">{{ $priceLabel ?: '—' }}</td>
                        <td class="num">{{ $amount !== null ? \App\Support\Format::money($amount) : '—' }}</td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showUnitPrices ? 4 : 2 }}">Geen werkzaamheden vastgelegd.</td>
                    </tr>
                @endforelse
                @if ($showUnitPrices)
                    <tr class="total">
                        <td colspan="3">Totaal opdrachtbedrag</td>
                        <td class="num">{{ $total !== null ? \App\Support\Format::money($total) : '—' }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($showPrices && $billing === \App\Enums\WorkTicketBilling::Hourly)
        <div class="section">
            <div class="section-title">Prijsafspraak</div>
            <p>Uurprijs {{ \App\Support\Format::money($ticket->hourly_rate) }} / uur</p>
            @if ($ticket->worked_hours !== null)
                <p>{{ \App\Support\Format::hours($ticket->worked_hours) }} × {{ \App\Support\Format::money($ticket->hourly_rate) }} = <strong>{{ \App\Support\Format::money($ticket->totalAmount()) }}</strong></p>
            @else
                <p>Uren nog niet geregistreerd.</p>
            @endif
        </div>
    @elseif ($showPrices && $billing === \App\Enums\WorkTicketBilling::Fixed)
        <div class="section">
            <div class="section-title">Prijsafspraak</div>
            <p>Vaste prijs <strong>{{ \App\Support\Format::money($ticket->fixed_price) }}</strong></p>
        </div>
    @elseif ($showPrices && ($priceMode ?? null) === 'hourly')
        <div class="section">
            <div class="section-title">Prijsafspraak</div>
            <p>{{ $priceNote ?? 'Uurprijs' }}</p>
        </div>
    @elseif ($showPrices && ($priceMode ?? null) === 'fixed')
        <div class="section">
            <div class="section-title">Prijsafspraak</div>
            <p>{{ $priceNote ?? 'Vaste prijs' }}</p>
        </div>
    @endif

    @if (! $isPdf && $floorLayers === [] && $drawingItems !== [])
        <div class="section drawings">
            <div class="section-title">Tekeningen</div>
            @foreach ($drawingItems as $drawing)
                @php
                    $name = is_array($drawing) ? ($drawing['name'] ?? 'Tekening') : ($drawing->original_filename ?: 'Tekening');
                    $url = is_array($drawing) ? ($drawing['url'] ?? null) : null;
                    $isImage = is_array($drawing) ? (bool) ($drawing['is_image'] ?? false) : false;
                    if (! is_array($drawing) && isset($project)) {
                        $url = route('projects.documents.show', [$project, $drawing]);
                        $isImage = $drawing->isImage();
                    }
                @endphp
                <div class="drawing">
                    @if ($url)
                        <a href="{{ $url }}">
                            @if ($isImage)
                                <img src="{{ $url }}" alt="{{ $name }}">
                            @endif
                            <div class="drawing-name">{{ $name }}</div>
                        </a>
                    @else
                        <div>{{ $name }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($notesText !== '')
        <div class="section">
            <div class="section-title">Opmerkingen / werkinstructies</div>
            <div class="notes">{{ $notesText }}</div>
        </div>
    @endif

    @if ($colleagues !== [])
        <div class="section">
            <div class="section-title">Ook op het werk</div>
            <p>{{ implode(', ', $colleagues) }}</p>
        </div>
    @endif

    <div class="foot">{{ $companyName }} · {{ $kindLabel }} {{ $number }}</div>
</div>
@if ($isPdf && ($pdfDrawingLayers !== [] || $pdfDrawingItems !== []))
    @foreach ($pdfDrawingLayers as $layer)
        <div class="drawing-page">
            <div class="section-title">Tekening</div>
            <p><strong>{{ $layer['name'] }}</strong></p>
            <div class="map" data-page="{{ (int) ($layer['page'] ?? 0) }}">
                @if (filled($layer['image'] ?? null))
                    <img class="map-drawing" src="{{ $layer['image'] }}" alt="">
                @elseif ($drawingIsPdf)
                    <p class="map-loading">Tekening laden…</p>
                @endif
                @foreach ($layer['pins'] ?? [] as $pin)
                    <span class="room-pin" style="left: {{ $pin['x'] * 100 }}%; top: {{ $pin['y'] * 100 }}%;">{{ $pin['label'] }}</span>
                @endforeach
            </div>
            @if (filled($drawingName))
                <div class="drawing-name">{{ $drawingName }}</div>
            @endif
        </div>
    @endforeach
    @foreach ($pdfDrawingItems as $drawing)
        @php
            $name = is_array($drawing) ? ($drawing['name'] ?? 'Tekening') : ($drawing->original_filename ?: 'Tekening');
            $embed = is_array($drawing) ? ($drawing['path'] ?? null) : null;
        @endphp
        <div class="drawing-page">
            <div class="section-title">Tekening</div>
            @if ($embed)
                <img src="{{ $embed }}" alt="{{ $name }}">
            @endif
            <div class="drawing-name">{{ $name }}</div>
        </div>
    @endforeach
@endif
@if ($isPdf && ($includeMeasurementForm ?? false) && is_array($measurementForm ?? null))
    @include('measurement-forms._document', $measurementForm)
@endif
