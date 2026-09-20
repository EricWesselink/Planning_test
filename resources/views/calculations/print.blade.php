<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title> </title>
    @vite(['resources/css/app.css', 'resources/js/calculation-print.js'])
    <style>
        @media print {
            @page {
                margin: 0;
                @top-left { content: ""; }
                @top-center { content: ""; }
                @top-right { content: ""; }
                @bottom-left { content: ""; }
                @bottom-center { content: ""; }
                @bottom-right { content: ""; }
            }
            html, body { margin: 0 !important; }
        }
    </style>
</head>
<body class="calc-print">
    @php
        $include = $document['include'];
        $output = $document['output'];
    @endphp
    <p class="calc-print-toolbar no-print">
        <a href="{{ url()->previous(route('calculations.index')) }}">← Terug</a>
        <button type="button" class="bg-nicon-orange px-3 py-1.5 text-sm text-white" data-print-start disabled onclick="niconPrintCalculation()">
            {{ $output === 'print' ? 'Afdrukken' : 'PDF maken' }}
        </button>
    </p>
    <p id="calc-print-status" class="calc-print-status no-print">
        @if ($document['show_drawings'])
            Tekeningen opbouwen…
        @endif
        {{ $output === 'print' ? 'Kies A3 liggend in het afdrukvenster. Zet kop- en voetteksten uit.' : 'Klik op PDF maken. De PDF wordt als A3 liggend gedownload, zonder browserkop.' }}
    </p>

    <div id="calc-print-sheets"></div>

    @foreach ($document['room_lists'] as $list)
        <section class="calc-print-page calc-print-table-page">
            <header class="calc-print-head">
                <div>
                    <p class="calc-print-kicker">{{ $document['calculation']['name'] }}</p>
                    <h1>Ruimtelijst · {{ $list['label'] }}</h1>
                </div>
            </header>
            <table class="calc-print-table">
                <thead>
                    <tr>
                        <th>Nummer</th>
                        <th>Naam</th>
                        <th>Vloer</th>
                        <th class="num">m²</th>
                        <th>Plint</th>
                        <th class="num">m¹</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($list['rooms'] as $room)
                    <tr>
                        <td>{{ $room['number'] ?: '—' }}</td>
                        <td>{{ $room['name'] ?: '—' }}</td>
                        <td>{{ $room['floor_codes_label'] ?: '—' }}</td>
                        <td class="num">{{ $room['m2_label'] }}</td>
                        <td>{{ $room['plinth_code'] ?: '—' }}</td>
                        <td class="num">{{ $room['plinth_quantity_label'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Geen ruimtes op deze tekening.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endforeach

    @if ($include['area_totals'])
        <section class="calc-print-page calc-print-table-page">
            <header class="calc-print-head">
                <div>
                    <p class="calc-print-kicker">{{ $document['calculation']['name'] }}</p>
                    <h1>Materiaaltotalen m²</h1>
                </div>
            </header>
            <table class="calc-print-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Product</th>
                        <th class="num">Totaal</th>
                        <th>Eenheid</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($document['area_totals'] as $total)
                    <tr>
                        <td>{{ $total['product_code'] ?: '—' }}</td>
                        <td>{{ $total['product'] ?: '—' }}</td>
                        <td class="num">{{ $total['quantity_label'] }}</td>
                        <td>{{ $total['unit_label'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Geen m²-totalen voor deze selectie.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endif

    @if ($include['plinth_totals'])
        <section class="calc-print-page calc-print-table-page">
            <header class="calc-print-head">
                <div>
                    <p class="calc-print-kicker">{{ $document['calculation']['name'] }}</p>
                    <h1>Plinttotalen m¹</h1>
                </div>
            </header>
            <table class="calc-print-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Product</th>
                        <th class="num">Totaal</th>
                        <th>Eenheid</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($document['plinth_totals'] as $total)
                    <tr>
                        <td>{{ $total['product_code'] ?: '—' }}</td>
                        <td>{{ $total['product'] ?: '—' }}</td>
                        <td class="num">{{ $total['quantity_label'] }}</td>
                        <td>{{ $total['unit_label'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">Geen plinttotalen voor deze selectie.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endif

    @if ($include['details'])
        <section class="calc-print-page calc-print-table-page">
            <header class="calc-print-head">
                <div>
                    <p class="calc-print-kicker">{{ $document['calculation']['name'] }}</p>
                    <h1>Detailregels calculatie</h1>
                </div>
            </header>
            <table class="calc-print-table">
                <thead>
                    <tr>
                        <th>Tekening</th>
                        <th>Nummer</th>
                        <th>Naam</th>
                        <th>Vloer</th>
                        <th class="num">m²</th>
                        <th>Plint</th>
                        <th class="num">m¹</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($document['details'] as $row)
                    <tr>
                        <td>{{ $row['group'] }}</td>
                        <td>{{ $row['number'] ?: '—' }}</td>
                        <td>{{ $row['name'] ?: '—' }}</td>
                        <td>{{ $row['floor_codes_label'] ?: '—' }}{{ $row['floor_product'] ? ' '.$row['floor_product'] : '' }}</td>
                        <td class="num">{{ $row['m2_label'] }}</td>
                        <td>{{ $row['plinth_code'] ?: '—' }}{{ $row['plinth_product'] ? ' '.$row['plinth_product'] : '' }}</td>
                        <td class="num">{{ $row['plinth_quantity_label'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">Geen regels voor deze selectie.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endif

    <script type="application/json" id="calc-print-data">{!! json_encode($document, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
</body>
</html>
