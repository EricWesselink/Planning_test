@extends('layouts.app')

@section('title', 'Calculatie zonder m² · Nicon Planning')

@section('content')
    <a href="{{ route('calculations.area-without-m2.create') }}" class="text-sm text-nicon-muted">← Nieuwe proef</a>
    <h1 class="mt-2 text-2xl font-semibold">Calculatie zonder m²</h1>
    <p class="mt-1 text-sm text-nicon-muted">
        Proefresultaat voor {{ $trial['filename'] ?: 'tekening' }}.
        Berekende m² komt uit maatvoering of contour. De m² op de tekening is alleen controle.
    </p>
    <p class="mt-2 text-sm font-medium">{{ $trial['source_label'] ?? 'Brontype: PDF met tekstlaag' }}</p>
    @if (! empty($trial['ocr_engine']))
        <p class="text-xs text-nicon-muted">Tekstherkenning: {{ $trial['ocr_engine'] }} (Tesseract via pdftoppm)</p>
    @endif

    @php
        $timingLabels = $trial['timing_labels'] ?? [];
        $join = static fn (array $items): string => $items === [] ? '—' : implode(', ', $items);
        $received = is_array($trial['received_upload'] ?? null) ? $trial['received_upload'] : [];
        $receivedFiles = is_array($received['all_files'] ?? null) ? $received['all_files'] : [];
        $label = static fn (mixed $value): string => ($value === null || $value === '') ? '—' : (string) $value;
        $mismatch = (string) ($received['mismatch'] ?? '');
        $geometryDebug = is_array($trial['geometry_debug'] ?? null) ? $trial['geometry_debug'] : [];
    @endphp
    <div class="mt-4 border border-nicon-warn bg-white p-4 text-sm space-y-1">
        <p class="font-medium">Ontvangen upload</p>
        <p><span class="text-nicon-muted">Browser gekozen bestand</span> · {{ $label($received['client_filename'] ?? '') }}</p>
        <p><span class="text-nicon-muted">POST originalName</span> · {{ $label($received['original_name'] ?? '') }}</p>
        <p><span class="text-nicon-muted">POST mime</span> · {{ $label($received['mime'] ?? '') }}</p>
        <p><span class="text-nicon-muted">POST grootte</span> · {{ isset($received['size']) ? number_format((int) $received['size'], 0, ',', '.').' bytes' : '—' }}</p>
        <p><span class="text-nicon-muted">SHA-256 ontvangen tempbestand</span> · {{ $label($received['tmp_sha256'] ?? '') }}</p>
        <p><span class="text-nicon-muted">SHA-256 opgeslagen/analysebestand</span> · {{ $label($received['stored_sha256'] ?? ($trial['file_hash'] ?? '')) }}</p>
        <p><span class="text-nicon-muted">HTML input-name</span> · {{ $label($received['input_name_read'] ?? 'drawing') }}</p>
        <p><span class="text-nicon-muted">Browser input-name</span> · {{ $label($received['client_input_name'] ?? '') }}</p>
        <p><span class="text-nicon-muted">Aantal file-inputs in de browser</span> · {{ $label($received['client_file_input_count'] ?? '') }}</p>
        <p><span class="text-nicon-muted">Namen van file-inputs</span> · {{ $label($received['client_file_input_names'] ?? '') }}</p>
        <p><span class="text-nicon-muted">Route</span> · {{ $label($received['route'] ?? '') }}</p>
        <p><span class="text-nicon-muted">Controller</span> · {{ $label($received['controller'] ?? '') }}</p>
        <p><span class="text-nicon-muted">Temp-pad</span> · {{ $label($received['tmp_path'] ?? '') }}</p>
        <p class="font-medium {{ str_starts_with($mismatch, 'Wissel') ? 'text-nicon-danger' : 'text-nicon-ink' }}">{{ $label($mismatch) }}</p>
        @foreach ($receivedFiles as $incoming)
            <p><span class="text-nicon-muted">allFiles {{ $incoming['field'] ?? '' }}</span> · {{ $incoming['original_name'] ?? '' }} · {{ number_format((int) ($incoming['size'] ?? 0), 0, ',', '.') }} bytes · {{ $incoming['sha256'] ?? '' }}</p>
        @endforeach
    </div>

    <div class="mt-4 border border-nicon-line bg-white p-4 text-sm space-y-1">
        <p><span class="text-nicon-muted">Brontype</span> · {{ str_replace('Brontype: ', '', (string) ($trial['source_label'] ?? '')) }}</p>
        <p><span class="text-nicon-muted">Herkende ruimtes</span> · {{ $join($trial['recognized_room_names'] ?? []) }}</p>
        <p><span class="text-nicon-muted">Herkende maatvoering</span> · {{ $join(array_map(strval(...), $trial['recognized_dimension_values'] ?? [])) }}</p>
        <p><span class="text-nicon-muted">Berekende ruimtes</span> · {{ $join($trial['calculated_room_labels'] ?? []) }}</p>
        <p><span class="text-nicon-muted">Niet berekenbare ruimtes</span> · {{ $join($trial['unavailable_room_labels'] ?? []) }}</p>
        <p><span class="text-nicon-muted">Rendertijd</span> · {{ $timingLabels['render'] ?? '0,00 s' }}</p>
        <p><span class="text-nicon-muted">OCR-tijd</span> · {{ $timingLabels['ocr'] ?? '0,00 s' }}</p>
        <p><span class="text-nicon-muted">Analysetijd</span> · {{ $timingLabels['rooms'] ?? '0,00 s' }}</p>
        <p><span class="text-nicon-muted">Totale tijd</span> · {{ $timingLabels['total'] ?? '0,00 s' }}</p>
        <p><span class="text-nicon-muted">Uploadnaam</span> · {{ $trial['original_filename'] ?: ($trial['filename'] ?: '—') }}</p>
        <p><span class="text-nicon-muted">Geanalyseerd pad</span> · {{ $trial['analyzed_path'] ?: '—' }}</p>
        <p><span class="text-nicon-muted">Bestandsgrootte</span> · {{ isset($trial['file_size']) ? number_format((int) $trial['file_size'], 0, ',', '.').' bytes' : '—' }}</p>
        <p><span class="text-nicon-muted">SHA-256</span> · {{ $trial['file_hash'] ?: '—' }}</p>
        <p class="text-xs text-nicon-muted">Coördinaten: PDF-up (y=0 onderaan). Overlay spiegelt y naar schermruimte.</p>
        @if (! empty($trial['ocr_mean_confidence']))
            <p><span class="text-nicon-muted">OCR-confidence</span> · {{ number_format((float) $trial['ocr_mean_confidence'], 0, ',', '.') }}</p>
        @endif
        @if (! empty($timingLabels['line']))
            <p class="pt-2 text-xs text-nicon-muted">{{ $timingLabels['line'] }}</p>
        @endif
    </div>

    @if ($trial['warnings'] !== [])
        <ul class="mt-4 list-disc pl-5 text-sm text-nicon-warn">
            @foreach ($trial['warnings'] as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-left text-white">
                <tr>
                    <th class="px-3 py-2">Ruimte</th>
                    <th class="px-3 py-2">Ruimtenr.</th>
                    <th class="px-3 py-2">Herkende maten</th>
                    <th class="px-3 py-2">Methode</th>
                    <th class="px-3 py-2">Berekende m²</th>
                    <th class="px-3 py-2">Werkelijke m²</th>
                    <th class="px-3 py-2">Afwijking</th>
                    <th class="px-3 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($trial['rooms'] as $room)
                <tr class="border-t border-nicon-line align-top">
                    <td class="px-3 py-2">{{ $room['room_name'] ?: '—' }}</td>
                    <td class="px-3 py-2">{{ $room['room_number'] ?: '—' }}</td>
                    <td class="px-3 py-2">{{ $room['dimensions_label'] }}</td>
                    <td class="px-3 py-2">{{ $room['method'] }}</td>
                    <td class="px-3 py-2">{{ $room['calculated_label'] }}</td>
                    <td class="px-3 py-2">{{ $room['printed_label'] }}</td>
                    <td class="px-3 py-2">{{ $room['deviation_label'] }}</td>
                    <td class="px-3 py-2 font-medium
                        @if ($room['status'] === 'reliable') text-nicon-ok
                        @elseif ($room['status'] === 'review') text-nicon-warn
                        @else text-nicon-danger
                        @endif">{{ $room['status_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-3 py-8 text-center text-nicon-muted">Geen ruimtes gevonden.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($geometryDebug !== [])
        <div data-geometry-debug class="mt-4 border border-nicon-line bg-white p-4 text-sm space-y-1">
            <p class="font-medium">Geometrie-diagnose</p>
            <p>{{ $geometryDebug['right'] ?? 'rechter buitengevel: niet gevonden' }}</p>
            <p class="text-nicon-muted">{{ $geometryDebug['right_detail'] ?? '' }}</p>
            <p>{{ $geometryDebug['bottom'] ?? 'onderste buitengevel: niet gevonden' }}</p>
            <p class="text-nicon-muted">{{ $geometryDebug['bottom_detail'] ?? '' }}</p>
            <p class="text-xs text-nicon-muted">{{ $geometryDebug['counts'] ?? '' }}</p>
            @foreach ($geometryDebug['log'] ?? [] as $line)
                <p class="text-xs font-mono text-nicon-muted">{{ $line }}</p>
            @endforeach
        </div>
    @endif

    @if (! empty($trial['has_preview']))
        <div data-area-without-m2-overlay class="relative mt-6 max-w-4xl overflow-hidden border border-nicon-line bg-white">
            <img src="{{ route('calculations.area-without-m2.preview', $trial['id']) }}" alt="Tekening met herkende ruimtes" class="block w-full">
            @foreach ($trial['rooms'] as $room)
                @php
                    $chip = is_array($room['overlay']['chip'] ?? null) ? $room['overlay']['chip'] : null;
                    if ($chip === null && isset($room['overlay']['anchor']['x'], $room['overlay']['anchor']['y'])) {
                        $chip = [
                            'x' => $room['overlay']['anchor']['x'],
                            'y' => $room['overlay']['anchor']['y'],
                            'text' => $room['overlay']['anchor']['label'] ?? ($room['room_name'] ?: ($room['room_number'] ?: 'Ruimte')),
                            'bg' => '#be0032',
                            'fg' => '#fff',
                        ];
                    }
                @endphp
                @if (! isset($chip['x'], $chip['y'], $chip['text']))
                    @continue
                @endif
                <span
                    data-ocr-label
                    class="calc-code-chip"
                    style="left: {{ $chip['x'] }}%; top: {{ $chip['y'] }}%; transform: translate(-50%, -50%); background: {{ $chip['bg'] ?? '#be0032' }}; color: {{ $chip['fg'] ?? '#fff' }}; pointer-events: none; white-space: nowrap;"
                >{{ $chip['text'] }}</span>
            @endforeach
        </div>
    @endif

    @foreach ($trial['rooms'] as $room)
        <div class="mt-4 border border-nicon-line bg-white p-4 text-sm">
            <p class="font-medium">{{ trim(($room['room_name'] ?? '').' '.($room['room_number'] ?? '')) ?: 'Ruimte' }}</p>
            <p class="mt-1">Berekend: {{ $room['calculated_label'] }}</p>
            <p>Werkelijk op tekening: {{ $room['printed_label'] }}</p>
            <p>Afwijking: {{ $room['deviation_label'] }}</p>
            <p class="mt-2 text-nicon-muted">{{ $room['trace'] }}</p>
            <div class="mt-3 border-t border-nicon-line pt-3 text-xs text-nicon-muted space-y-1">
                <p>OCR-positie ruimtenaam: x={{ $room['ocr_x'] ?? '—' }}, y={{ $room['ocr_y'] ?? '—' }}</p>
                <p>Methode: {{ $room['method'] ?? '—' }}</p>
                <p>Linkerwand: {{ $room['wall_left'] ?? '—' }}</p>
                <p>Rechterwand: {{ $room['wall_right'] ?? '—' }}</p>
                <p>Bovenwand: {{ $room['wall_top'] ?? '—' }}</p>
                <p>Onderwand: {{ $room['wall_bottom'] ?? '—' }}</p>
                @if (($room['wall_debug']['tested_pair'] ?? []) !== [])
                    <p>Getest wandpaar verticaal: {{ $room['wall_debug']['tested_pair']['vertical'] ?? '—' }}</p>
                    <p>Getest wandpaar horizontaal: {{ $room['wall_debug']['tested_pair']['horizontal'] ?? '—' }}</p>
                @endif
                @if (($room['wall_debug']['bands_v'] ?? []) !== [])
                    <p class="mt-2 font-medium text-nicon-ink">Dikke verticale banden</p>
                    <ul class="list-disc pl-5">
                        @foreach ($room['wall_debug']['bands_v'] as $band)
                            <li>{{ $band }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (($room['wall_debug']['bands_h'] ?? []) !== [])
                    <p class="mt-2 font-medium text-nicon-ink">Dikke horizontale banden</p>
                    <ul class="list-disc pl-5">
                        @foreach ($room['wall_debug']['bands_h'] as $band)
                            <li>{{ $band }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (($room['wall_debug']['axes'] ?? []) !== [])
                    <p class="mt-2 font-medium text-nicon-ink">Samengevoegde wandassen</p>
                    <ul class="list-disc pl-5">
                        @foreach ($room['wall_debug']['axes'] as $axis)
                            <li>{{ $axis }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (($room['wall_debug']['vertical'] ?? []) !== [])
                    <p class="mt-2 font-medium text-nicon-ink">Verticale wandkandidaten</p>
                    <ul class="list-disc pl-5">
                        @foreach ($room['wall_debug']['vertical'] as $candidate)
                            <li>{{ $candidate['span'] ?? '' }} · {{ $candidate['kind'] ?? 'lijn' }} · {{ $candidate['role'] ?? '—' }} · afstand {{ $candidate['dist'] ?? '—' }} px · {{ $candidate['decision'] ?? '' }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (($room['wall_debug']['horizontal'] ?? []) !== [])
                    <p class="mt-2 font-medium text-nicon-ink">Horizontale wandkandidaten</p>
                    <ul class="list-disc pl-5">
                        @foreach ($room['wall_debug']['horizontal'] as $candidate)
                            <li>{{ $candidate['span'] ?? '' }} · {{ $candidate['kind'] ?? 'lijn' }} · {{ $candidate['role'] ?? '—' }} · afstand {{ $candidate['dist'] ?? '—' }} px · {{ $candidate['decision'] ?? '' }}</li>
                        @endforeach
                    </ul>
                @endif
                <p>Virtueel gesloten gaten: {{ $room['closed_gaps_label'] ?? 'geen' }}</p>
                <p>Getekende schaal: {{ isset($room['drawing_scale']) ? '1:'.$room['drawing_scale'] : '—' }}</p>
                <p>Schaal: {{ isset($room['scale_mm_per_px']) ? str_replace('.', ',', (string) $room['scale_mm_per_px']).' mm/px' : '—' }}</p>
                <p>Gekozen horizontale maat: {{ $room['horizontal_mm'] ?? '—' }} · {{ $room['horizontal_position'] ?? '—' }}</p>
                <p>Maatsegment horizontaal: {{ $room['horizontal_segment'] ?? '—' }}</p>
                <p>Wanden horizontale maat: {{ $room['horizontal_walls'] ?? '—' }}</p>
                <p>Gekozen verticale maat: {{ $room['vertical_mm'] ?? '—' }} · {{ $room['vertical_position'] ?? '—' }}</p>
                <p>Maatsegment verticaal: {{ $room['vertical_segment'] ?? '—' }}</p>
                <p>Wanden verticale maat: {{ $room['vertical_walls'] ?? '—' }}</p>
                <p>Confidence: {{ number_format((float) ($room['confidence'] ?? 0), 2, ',', '.') }}</p>
                @if (($room['rejected'] ?? []) !== [])
                    <p class="mt-2 font-medium text-nicon-ink">Afgewezen maten</p>
                    <ul class="list-disc pl-5">
                        @foreach ($room['rejected'] as $rejected)
                            <li>{{ $rejected['mm'] ?? '?' }} — {{ $rejected['reason'] ?? '' }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endforeach
@endsection
