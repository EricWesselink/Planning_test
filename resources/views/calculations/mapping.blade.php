@extends('layouts.app')

@section('title', 'Excel koppelen · '.$calculation->name)

@section('content')
    <a href="{{ route('calculations.imported', $calculation) }}" class="text-sm text-nicon-muted">← Overzicht</a>
    <h1 class="mt-2 text-2xl font-semibold">Geavanceerd: kolommapping</h1>
    <p class="mt-1 text-sm text-nicon-muted">Alleen nodig als het systeem een Excelbestand niet automatisch kan lezen. Excelbestanden hebben geen vast format.</p>

    @if (session('status'))
        <p class="mt-3 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-3 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('calculations.workbooks.update', $calculation) }}" class="mt-4 space-y-4">
        @csrf
        @forelse ($calculation->workbooks as $workbook)
            @php
                $analysis = $workbook->analysis ?? [];
                $sheets = $analysis['sheets'] ?? [];
            @endphp
            <section class="border border-nicon-line bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="font-medium">{{ $workbook->original_filename }}</h2>
                        <p class="text-xs text-nicon-muted">{{ count($sheets) }} tabblad(en) · {{ $workbook->status === 'pending' ? 'nog koppelen' : $workbook->status }}</p>
                    </div>
                    @if ($workbook->existsOnDisk())
                        <a href="{{ route('calculations.workbooks.show', [$calculation, $workbook]) }}" class="text-xs text-nicon-orange-dark" target="_blank" rel="noopener">Bestand openen</a>
                    @endif
                </div>

                @if (($analysis['skippable'] ?? false) && ($analysis['skip_reason'] ?? null))
                    <p class="mt-2 text-sm text-nicon-warn">{{ $analysis['skip_reason'] }}</p>
                @endif

                @if ($workbook->status !== 'pending')
                    <p class="mt-2 text-sm text-nicon-muted">Dit bestand is al verwerkt.</p>
                @else
                    <label class="mt-3 flex items-center gap-2 text-sm">
                        <input type="hidden" name="workbooks[{{ $workbook->id }}][skip]" value="0">
                        <input type="checkbox" name="workbooks[{{ $workbook->id }}][skip]" value="1" @checked($analysis['skippable'] ?? false)>
                        Dit Excelbestand overslaan (calculatie loopt gewoon door)
                    </label>

                    @foreach ($sheets as $sheetIndex => $sheet)
                        @php
                            $groups = $sheet['groups'] ?? [];
                            if ($groups === []) {
                                $groups = [['columns' => [], 'confidence' => [], 'product_hint' => $sheet['product_hint'] ?? null, 'header_row' => $sheet['header_row'] ?? null]];
                            }
                            $columnOptions = [];
                            foreach ($sheet['labels'] ?? [] as $label) {
                                $columnOptions[$label['column']] = $label['header'];
                            }
                            for ($i = 0; $i < 16; $i++) {
                                $letter = $guesser->columnLetter($i);
                                $columnOptions[$letter] ??= 'Kolom '.$letter;
                            }
                        @endphp
                        <div class="mt-4 border border-nicon-line p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-medium">Tabblad {{ $sheet['name'] }}</h3>
                                <label class="text-xs">
                                    <input type="hidden" name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][skip]" value="0">
                                    <input type="checkbox" name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][skip]" value="1" @checked($sheet['skippable'] ?? false)>
                                    Tabblad overslaan
                                </label>
                            </div>
                            <input type="hidden" name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][name]" value="{{ $sheet['name'] }}">
                            <input type="hidden" name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][header_row]" value="{{ $sheet['header_row'] }}">
                            <input type="hidden" name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][product_hint]" value="{{ $sheet['product_hint'] }}">

                            @if (($sheet['labels'] ?? []) !== [])
                                <ul class="mt-2 list-disc pl-5 text-xs text-nicon-muted">
                                    @foreach ($sheet['labels'] as $label)
                                        <li>
                                            Kolom "{{ $label['header'] }}" → {{ $guesser->roleLabel($label['role']) }}
                                            @if (($label['confidence'] ?? '') === 'review')
                                                <span class="text-nicon-warn">(controleren)</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @foreach ($groups as $groupIndex => $group)
                                <input type="hidden" name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][groups][{{ $groupIndex }}][header_row]" value="{{ $group['header_row'] ?? $sheet['header_row'] }}">
                                <input type="hidden" name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][groups][{{ $groupIndex }}][product_hint]" value="{{ $group['product_hint'] ?? $sheet['product_hint'] }}">
                                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($roles as $role)
                                        @php
                                            $selected = $group['columns'][$role] ?? '';
                                        @endphp
                                        <div>
                                            <label class="block text-[11px] uppercase tracking-wide text-nicon-muted">{{ $guesser->roleLabel($role) }}</label>
                                            <select name="workbooks[{{ $workbook->id }}][sheets][{{ $sheetIndex }}][groups][{{ $groupIndex }}][columns][{{ $role }}]" class="mt-0.5 w-full border border-nicon-line px-2 py-1 text-sm">
                                                <option value="">Niet gebruiken</option>
                                                @for ($i = 0; $i < 16; $i++)
                                                    <option value="{{ $i }}" @selected($selected !== '' && (int) $selected === $i)>
                                                        {{ $guesser->columnLetter($i) }}{{ isset($columnOptions[$guesser->columnLetter($i)]) && ! str_starts_with($columnOptions[$guesser->columnLetter($i)], 'Kolom ') ? ' · '.$columnOptions[$guesser->columnLetter($i)] : '' }}
                                                    </option>
                                                @endfor
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </section>
        @empty
            <p class="text-sm text-nicon-muted">Geen Excelbestanden om te koppelen.</p>
        @endforelse

        @if ($calculation->workbooks->contains(fn ($workbook) => $workbook->status === 'pending'))
            <button class="bg-nicon-ink px-4 py-2 text-sm text-white">Mapping toepassen</button>
        @endif
    </form>
@endsection
