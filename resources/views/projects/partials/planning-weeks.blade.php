@php
    $idPrefix = $idPrefix ?? '';
    $compact = $compact ?? false;
    $useOld = $useOld ?? true;
    $side = $side ?? 'both';
    $formId = $formId ?? null;
    $table = $table ?? false;
    $inputClass = $compact || $table
        ? 'mt-1 w-full border border-nicon-line px-2 py-1'
        : 'mt-1 w-full border border-nicon-line px-3 py-2';
    $tableInput = 'border border-nicon-line bg-white px-1.5 py-1 text-sm leading-tight';
    $yearPlaceholder = now()->isoWeekYear;
    $startYear = $useOld ? old('start_year', $startYear ?? null) : ($startYear ?? null);
    $startWeek = $useOld ? old('start_week', $startWeek ?? null) : ($startWeek ?? null);
    $klaarYear = $useOld ? old('klaar_year', $klaarYear ?? null) : ($klaarYear ?? null);
    $klaarWeek = $useOld ? old('klaar_week', $klaarWeek ?? null) : ($klaarWeek ?? null);
    $startDate = $useOld ? old('start_date', $startDate ?? null) : ($startDate ?? null);
    $klaarDate = $useOld ? old('klaar_date', $klaarDate ?? null) : ($klaarDate ?? null);
    $showStart = $side === 'both' || $side === 'start';
    $showKlaar = $side === 'both' || $side === 'klaar';
@endphp
@if ($table)
    <div class="flex items-center gap-1 whitespace-nowrap">
        @if ($showStart)
            <input id="{{ $idPrefix }}start_date" name="start_date" type="date" value="{{ $startDate }}" aria-label="Startdatum" title="Datum" class="{{ $tableInput }} w-[9.75rem]" @if ($formId) form="{{ $formId }}" @endif>
            <input id="{{ $idPrefix }}start_year" name="start_year" type="number" min="2000" max="2100" inputmode="numeric" value="{{ $startYear }}" aria-label="Startjaar" title="Jaar" placeholder="jaar" class="{{ $tableInput }} w-14 text-center" @if ($formId) form="{{ $formId }}" @endif>
            <input id="{{ $idPrefix }}start_week" name="start_week" type="number" min="1" max="53" inputmode="numeric" value="{{ $startWeek }}" aria-label="Startweek" title="Week" placeholder="wk" class="{{ $tableInput }} w-11 text-center" @if ($formId) form="{{ $formId }}" @endif>
        @endif
        @if ($showKlaar)
            <input id="{{ $idPrefix }}klaar_date" name="klaar_date" type="date" value="{{ $klaarDate }}" aria-label="Einddatum" title="Datum" class="{{ $tableInput }} w-[9.75rem]" @if ($formId) form="{{ $formId }}" @endif>
            <input id="{{ $idPrefix }}klaar_year" name="klaar_year" type="number" min="2000" max="2100" inputmode="numeric" value="{{ $klaarYear }}" aria-label="Eindjaar" title="Jaar" placeholder="jaar" class="{{ $tableInput }} w-14 text-center" @if ($formId) form="{{ $formId }}" @endif>
            <input id="{{ $idPrefix }}klaar_week" name="klaar_week" type="number" min="1" max="53" inputmode="numeric" value="{{ $klaarWeek }}" aria-label="Eindweek" title="Week" placeholder="wk" class="{{ $tableInput }} w-11 text-center" @if ($formId) form="{{ $formId }}" @endif>
        @endif
    </div>
@else
<div class="grid gap-3 sm:grid-cols-2">
    @if ($showStart)
        <fieldset class="space-y-2">
            <legend class="text-xs uppercase tracking-wide text-nicon-muted">Start werk</legend>
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="{{ $idPrefix }}start_date">Datum</label>
                <input id="{{ $idPrefix }}start_date" name="start_date" type="date" value="{{ $startDate }}" class="{{ $inputClass }}" @if ($formId) form="{{ $formId }}" @endif>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="{{ $idPrefix }}start_year">Jaar</label>
                    <input id="{{ $idPrefix }}start_year" name="start_year" type="number" min="2000" max="2100" inputmode="numeric" value="{{ $startYear }}" class="{{ $inputClass }}" placeholder="{{ $yearPlaceholder }}" @if ($formId) form="{{ $formId }}" @endif>
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="{{ $idPrefix }}start_week">Weeknummer</label>
                    <input id="{{ $idPrefix }}start_week" name="start_week" type="number" min="1" max="53" inputmode="numeric" value="{{ $startWeek }}" class="{{ $inputClass }}" placeholder="40" @if ($formId) form="{{ $formId }}" @endif>
                </div>
            </div>
        </fieldset>
    @endif
    @if ($showKlaar)
        <fieldset class="space-y-2">
            <legend class="text-xs uppercase tracking-wide text-nicon-muted">Klaar werk</legend>
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="{{ $idPrefix }}klaar_date">Datum</label>
                <input id="{{ $idPrefix }}klaar_date" name="klaar_date" type="date" value="{{ $klaarDate }}" class="{{ $inputClass }}" @if ($formId) form="{{ $formId }}" @endif>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="{{ $idPrefix }}klaar_year">Jaar</label>
                    <input id="{{ $idPrefix }}klaar_year" name="klaar_year" type="number" min="2000" max="2100" inputmode="numeric" value="{{ $klaarYear }}" class="{{ $inputClass }}" placeholder="{{ $yearPlaceholder }}" @if ($formId) form="{{ $formId }}" @endif>
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="{{ $idPrefix }}klaar_week">Weeknummer</label>
                    <input id="{{ $idPrefix }}klaar_week" name="klaar_week" type="number" min="1" max="53" inputmode="numeric" value="{{ $klaarWeek }}" class="{{ $inputClass }}" placeholder="44" @if ($formId) form="{{ $formId }}" @endif>
                </div>
            </div>
        </fieldset>
    @endif
</div>
@endif
@if ($side === 'both' && ! $compact && ! $table)
    <p class="text-xs text-nicon-muted">Vul in wanneer het werk start en wanneer het klaar moet zijn (datum, of jaar + weeknummer). Op het dashboard zie je hoeveel dagen er nog over zijn.</p>
@endif
