@extends('layouts.app')

@section('title', 'Personeel · Nicon Planning')

@section('content')
    <div>
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Eigen personeel</div>
        <h1 class="text-2xl font-semibold">Personeel</h1>
        <p class="text-sm text-nicon-muted">Vaste werkdagen per persoon, los van het team. Uit = vaste vrije dag. ZZP staat hier niet.</p>
    </div>

    @include('workers._tabs', ['tab' => 'personnel'])

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

    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="min-w-full text-sm">
            <thead class="border-b border-nicon-line bg-nicon-paper text-left text-xs uppercase tracking-wide text-nicon-muted">
                <tr>
                    <th class="px-4 py-2 font-medium">Medewerker</th>
                    @foreach (\App\Models\CrewMember::WEEKDAY_LABELS as $label)
                        <th class="px-2 py-2 text-center font-medium">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $row)
                    @php
                        $worker = $row['worker'];
                        $member = $row['member'];
                    @endphp
                    <tr @class(['border-t border-nicon-line', 'opacity-60' => ! $worker->active])>
                        <td class="px-4 py-2">
                            <a href="{{ route('workers.show', $worker) }}" class="font-semibold text-nicon-ink">{{ $member->displayName() }}</a>
                            @if ($member->displayName() !== $worker->name)
                                <span class="block text-xs text-nicon-muted">{{ $worker->name }}</span>
                            @endif
                        </td>
                        @foreach (\App\Models\CrewMember::WEEKDAY_LABELS as $isoDay => $label)
                            <td class="px-2 py-2 text-center">
                                @if ($member->exists && auth()->user()?->can('update', $worker))
                                    <form method="POST" action="{{ route('workers.personnel.update', [$worker, $member]) }}" class="inline-flex items-center justify-center">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="day" value="{{ $isoDay }}">
                                        <input type="hidden" name="works" value="0">
                                        <label class="inline-flex cursor-pointer items-center justify-center">
                                            <input
                                                type="checkbox"
                                                name="works"
                                                value="1"
                                                class="size-4 border-nicon-line"
                                                @checked($member->worksOn($isoDay))
                                                onchange="this.form.submit()"
                                                aria-label="{{ $member->displayName() }} {{ $label }}"
                                            >
                                            <span class="sr-only">{{ $label }}</span>
                                        </label>
                                    </form>
                                @else
                                    <span class="text-nicon-muted">{{ $member->worksOn($isoDay) ? '✓' : 'Vrij' }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-nicon-muted">Nog geen eigen medewerkers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
