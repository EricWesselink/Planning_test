<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $voucher->type->label() }} {{ $voucher->number }}</title>
    <style>
        body { font-family: sans-serif; color: #1c1917; margin: 32px; }
        h1 { font-size: 22px; margin: 0; }
        h2 { font-size: 16px; margin: 0 0 4px; }
        .muted { color: #78716c; font-size: 12px; }
        .letterhead {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-start;
            padding-bottom: 16px;
            border-bottom: 2px solid #e4572e;
            margin-bottom: 20px;
        }
        .logo { display: block; height: 56px; width: auto; }
        .company { text-align: right; font-size: 12px; line-height: 1.45; color: #44403c; }
        .company strong { display: block; font-size: 14px; color: #1c1917; }
        .company a { color: #1c1917; text-decoration: none; }
        .head { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #e7e0d4; vertical-align: top; }
        th { color: #78716c; font-weight: 600; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .activity td { padding: 8px 6px; }
        .activity.has-rooms td { border-bottom: none; }
        .room td { padding: 2px 6px 2px 18px; font-size: 12px; color: #78716c; height: 22px; border-bottom: none; }
        .room td.num { color: #78716c; }
        .room-last td { border-bottom: 1px solid #e7e0d4; }
        .total td { font-weight: 700; border-top: 2px solid #1c1917; }
        .notes { margin-top: 18px; font-size: 13px; }
        .sign { display: flex; gap: 48px; margin-top: 48px; }
        .sign div { flex: 1; border-top: 1px solid #e7e0d4; padding-top: 8px; font-size: 12px; color: #78716c; }
        .foot { margin-top: 36px; font-size: 11px; color: #78716c; border-top: 1px solid #e7e0d4; padding-top: 10px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print">
        <a href="{{ route('production.index', array_filter(['worker_id' => $voucher->worker_id, 'project_id' => $voucher->project_id])) }}">Terug naar productie</a>
        <a href="{{ route('vouchers.pdf', $voucher) }}" style="margin-left:12px;background:#e4572e;color:#fff;padding:8px 14px;text-decoration:none;">Download PDF</a>
        <button onclick="window.print()" style="margin-left:12px">Afdrukken</button>
        @if ($canEdit ?? false)
            <a href="{{ route('vouchers.edit', $voucher) }}" style="margin-left:12px">Aanpassen</a>
        @endif
        @if ($canSend ?? false)
            @if (filled($voucher->worker?->email))
                <form method="POST" action="{{ route('vouchers.send', $voucher) }}" style="display:inline;margin-left:12px">
                    @csrf
                    <button type="submit">Verstuur naar vakman</button>
                </form>
            @elseif ($voucher->worker)
                <a href="{{ route('workers.show', $voucher->worker) }}" style="margin-left:12px">Vul eerst het e-mailadres van de vakman in</a>
            @endif
        @endif
    </p>
    @if ($canSend ?? false)
        <p class="no-print muted">De vakman krijgt deze bon per mail om bij zijn factuur te voegen.</p>
    @endif
    @if (session('status'))
        <p class="no-print" style="color:#3f6212">{{ session('status') }}</p>
    @endif
    @if (session('warning'))
        <p class="no-print" style="color:#b91c1c">{{ session('warning') }}</p>
    @endif
    @if ($errors->any())
        <p class="no-print" style="color:#b91c1c">{{ $errors->first() }}</p>
    @endif
    <header class="letterhead">
        <img class="logo" src="{{ asset(config('company.logo')) }}" alt="{{ config('company.name') }}">
        <div class="company">
            <strong>{{ config('company.name') }}</strong>
            {{ config('company.address') }}<br>
            {{ config('company.postal_code') }} {{ config('company.city') }}<br>
            <a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a><br>
            {{ config('company.phone') }}
        </div>
    </header>
    <div class="head">
        <div>
            <h1>{{ $voucher->type->label() }} {{ $voucher->number }}</h1>
            <p class="muted">{{ $voucher->issued_on?->format('d-m-Y') }}</p>
        </div>
        <div>
            <h2>{{ $voucher->worker?->displayName() }}</h2>
            <p class="muted">
                {{ $voucher->worker?->employment_type?->label() }}
                @if ($voucher->worker?->nawLine()) · {{ $voucher->worker->nawLine() }} @endif
            </p>
            <p>
                {{ $voucher->project?->project_number }} · {{ $voucher->project?->name }}
            </p>
            <p class="muted">
                {{ $voucher->project?->customer?->name }}
                @if ($voucher->project?->city) · {{ $voucher->project->city }} @endif
            </p>
            @if ($voucher->parent)
                <p class="muted">Bij opdrachtbon {{ $voucher->parent->number }}</p>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Omschrijving</th>
                <th class="num">Hoeveelheid</th>
                <th class="num">Prijs</th>
                <th class="num">Bedrag</th>
            </tr>
        </thead>
        <tbody>
            @foreach (\App\Support\VoucherActivityGroups::fromVoucher($voucher) as $group)
                <tr class="activity {{ $group['has_rooms'] ? 'has-rooms' : '' }}">
                    <td>{{ $group['description'] }}</td>
                    <td class="num">{{ \App\Support\VoucherActivityGroups::quantityLabel($group) }}</td>
                    <td class="num">{{ \App\Support\VoucherActivityGroups::priceLabel($group) }}</td>
                    <td class="num">{{ \App\Support\Format::money($group['amount']) }}</td>
                </tr>
                @if ($group['has_rooms'])
                    @foreach ($group['entries'] as $entry)
                        <tr class="room {{ $loop->last ? 'room-last' : '' }}">
                            <td>{{ $entry['room_label'] }}</td>
                            <td class="num">{{ \App\Support\Format::qty($entry['quantity'], 2) }} {{ \App\Support\VoucherActivityGroups::unitLabel($group['unit']) }}</td>
                            <td class="num"></td>
                            <td class="num"></td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            <tr class="total">
                <td colspan="3">{{ $voucher->type === \App\Enums\VoucherType::Opdracht ? 'Totaal' : 'Totaal te factureren' }}</td>
                <td class="num">{{ \App\Support\Format::money($voucher->total_amount) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($voucher->notes)
        <p class="notes">{{ $voucher->notes }}</p>
    @endif

    <div class="sign">
        <div>{{ config('company.name') }}</div>
        <div>{{ $voucher->worker?->displayName() }}</div>
    </div>
    <p class="foot">
        {{ config('company.name') }}
        · {{ config('company.address') }}
        · {{ config('company.postal_code') }} {{ config('company.city') }}
        · {{ config('company.email') }}
        · {{ config('company.phone') }}
    </p>
</body>
</html>
