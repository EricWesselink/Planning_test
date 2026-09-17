@php
    $logoSrc = $logo ?? $logoUrl ?? null;
    $companyPlace = trim(implode(' ', array_filter([$companyPostalCode ?? null, $companyCity ?? null])));
    $companyAddressLine = trim(implode(', ', array_filter([$companyAddress ?? null, $companyPlace])));
@endphp
<div class="measurement-page">
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
                <div class="block-title">Klant</div>
                <p><strong>{{ $customerName }}</strong></p>
                @if (filled($address))
                    <p>{{ $address }}</p>
                @endif
                <p>{{ trim(implode(' ', array_filter([$postalCode ?? null, $city ?? null]))) }}</p>
            </td>
            <td>
                <div class="block-title">Contact</div>
                @if (filled($phone))
                    <p>{{ $phone }}</p>
                @endif
                @if (filled($email))
                    <p>{{ $email }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="section">
        <table class="lines measurement-lines">
            <thead>
                <tr>
                    <th>Ruimte</th>
                    <th>Product</th>
                    <th>Merk</th>
                    <th>Type</th>
                    <th>Kleurnr.</th>
                    <th>M1/M2</th>
                    <th>Ondervloer</th>
                    <th>Plinten</th>
                    <th>Treden</th>
                    <th>Profiel</th>
                    <th>Aanwezig op locatie</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['room'] }}</td>
                        <td>{{ $row['product'] }}</td>
                        <td>{{ $row['brand'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['color_number'] }}</td>
                        <td>{{ $row['quantity'] }}</td>
                        <td>{{ $row['underlay'] }}</td>
                        <td>{{ $row['skirting'] }}</td>
                        <td>{{ $row['steps'] }}</td>
                        <td>{{ $row['profile'] }}</td>
                        <td>{{ $row['available_on_site'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11">Geen inmeetregels.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <table class="blocks" style="margin-top:14px">
        <tr>
            <td>
                <div class="block-title">Inmeter</div>
                <p>{{ $meterName ?: '—' }}</p>
            </td>
            <td>
                <div class="block-title">Besteld op</div>
                <p>{{ $orderedOn ?: '—' }}</p>
                <div class="block-title" style="margin-top:10px">Montage</div>
                <p>{{ $installationOn ?: '—' }}</p>
            </td>
        </tr>
    </table>
</div>
