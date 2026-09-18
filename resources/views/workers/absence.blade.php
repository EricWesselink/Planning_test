@extends('layouts.app')

@section('title', 'Afwezigheid · Nicon Planning')

@section('content')
    <div>
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Teams</div>
        <h1 class="text-2xl font-semibold">Afwezigheid</h1>
        <p class="text-sm text-nicon-muted">Alleen eigen vakmannen. Vaste vrije dagen, vakantie, ziekte, verlof en ADV. ZZP blijft in Teams.</p>
    </div>

    @include('workers._tabs', ['tab' => 'absence'])

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-6 grid gap-3">
        @forelse ($workers as $worker)
            <article @class(['border border-nicon-line bg-white', 'opacity-60' => ! $worker->active])>
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-nicon-line px-4 py-3">
                    <a href="{{ route('workers.show', $worker) }}" class="min-w-0 flex items-center gap-3">
                        <span class="inline-block size-3 shrink-0 rounded-full" style="background: {{ $worker->planColor() }}"></span>
                        <span>
                            <span class="block font-semibold text-nicon-ink">{{ $worker->name }}</span>
                            @if ($worker->crewNamesLabel())
                                <span class="block text-xs text-nicon-muted">{{ $worker->crewNamesLabel() }}</span>
                            @endif
                        </span>
                    </a>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="border border-nicon-line bg-nicon-paper px-2 py-0.5">{{ $worker->employment_type->label() }}</span>
                        @if (! $worker->active)
                            <span class="border border-nicon-line bg-nicon-paper px-2 py-0.5">Inactief</span>
                        @endif
                        @if ($worker->unavailable)
                            <span class="border border-nicon-danger/30 bg-red-50 px-2 py-0.5 text-nicon-danger">Niet beschikbaar</span>
                        @endif
                    </div>
                </div>
                <div class="space-y-3 px-4 py-3">
                    @include('workers._availability', ['worker' => $worker])
                </div>
            </article>
        @empty
            <p class="border border-nicon-line bg-white px-4 py-6 text-sm text-nicon-muted">Nog geen eigen vakmannen.</p>
        @endforelse
    </div>
@endsection
