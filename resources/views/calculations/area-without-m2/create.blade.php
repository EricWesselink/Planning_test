@extends('layouts.app')

@section('title', 'Calculatie zonder m² · Nicon Planning')

@section('content')
    <a href="{{ route('calculations.index') }}" class="text-sm text-nicon-muted">← Calculatie</a>
    <h1 class="mt-2 text-2xl font-semibold">Calculatie zonder m²</h1>
    <p class="mt-1 text-sm text-nicon-muted">Proefmodule. Berekent vloeroppervlak uit maatvoering en contouren. De m² op de tekening wordt niet gebruikt voor de berekening, alleen als controle achteraf. PDF’s met tekstlaag én scans zonder tekstlaag (pagina 1) kunnen worden onderzocht. Niets wordt naar een echte calculatie geschreven.</p>

    @if ($errors->any())
        <ul class="mt-4 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('calculations.area-without-m2.store') }}" enctype="multipart/form-data" autocomplete="off" data-area-without-m2-create class="mt-6 max-w-xl space-y-4 border border-nicon-line bg-white p-5">
        @csrf
        <input type="hidden" name="client_filename" value="" data-client-filename>
        <input type="hidden" name="client_size" value="" data-client-size>
        <input type="hidden" name="client_input_name" value="" data-client-input-name>
        <input type="hidden" name="client_file_input_count" value="" data-client-file-input-count>
        <input type="hidden" name="client_file_input_names" value="" data-client-file-input-names>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="drawing">PDF-tekening</label>
            <input id="drawing" type="file" name="drawing" accept=".pdf,application/pdf" required autocomplete="off" class="mt-1 w-full text-sm">
            <p data-chosen-filename class="mt-1 text-sm font-medium text-nicon-ink"></p>
            <p data-pageshow-debug class="mt-1 text-xs text-nicon-muted"></p>
            <p class="mt-1 text-xs text-nicon-muted">Eén plattegrond. Max. {{ $maxFileMegabytes }} MB. Originelen blijven alleen in deze proefmap staan. De regel “Gekozen bestand” komt rechtstreeks uit <code>input.files[0].name</code>.</p>
        </div>
        <button type="submit" data-area-without-m2-submit class="bg-nicon-ink px-5 py-3 font-medium text-white">Oppervlaktes bepalen</button>
        <div class="border-t border-nicon-line pt-3 text-xs text-nicon-muted space-y-1">
            <p class="font-medium text-nicon-ink">Formulierdiagnose</p>
            <p>Action · {{ route('calculations.area-without-m2.store') }}</p>
            <p>Controller · App\Http\Controllers\AreaWithoutM2TrialController::store</p>
            <p>Input-name die PHP leest · drawing</p>
            <pre data-file-input-inventory class="whitespace-pre-wrap text-nicon-ink"></pre>
        </div>
    </form>

    <div data-area-without-m2-progress class="fixed inset-0 z-[1100] hidden items-center justify-center bg-nicon-ink/70 p-6" hidden>
        <div class="w-full max-w-md border border-nicon-line bg-white p-6">
            <p class="font-medium">Oppervlaktes bepalen</p>
            <p data-progress-status class="mt-1 text-sm text-nicon-muted">Bezig…</p>
            <div data-progress-bar class="mt-4 h-2 bg-nicon-sand" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Voortgang">
                <div data-progress-fill class="h-2 w-[8%] bg-nicon-orange transition-[width] duration-200"></div>
            </div>
            <p data-progress-detail class="mt-3 text-xs text-nicon-muted">De tekening wordt geüpload. Daarna volgt het herkennen van ruimtes en maten.</p>
        </div>
    </div>
@endsection

@push('scripts')
    @vite(['resources/js/area-without-m2-create.js'])
@endpush
