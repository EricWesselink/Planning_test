@extends('layouts.app')

@section('title', $detail['heading'].' · Nicon Planning')

@section('main_class', 'p-3 sm:p-6')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ $weekUrl }}" class="text-sm text-nicon-orange">← Weekoverzicht</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $detail['heading'] }}</h1>
        <p class="text-sm text-nicon-muted">{{ auth()->user()?->name ?? $worker?->displayName() }}</p>
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

        @forelse ($detail['jobs'] as $job)
            @php
                $project = $job['project'];
            @endphp
            <article class="mt-4 border border-nicon-line bg-white">
                @include('vakman._agenda-card', ['job' => $job, 'showActions' => false])
                <div class="space-y-3 border-t border-nicon-line px-4 py-4 text-sm">
                    @if (($job['works'] ?? []) !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Werkzaamheden</div>
                            <ul class="mt-1 space-y-1">
                                @foreach ($job['works'] as $work)
                                    <li>
                                        {{ $work['title'] }} · {{ $work['quantity'] }} {{ $work['unit'] }}
                                        @if (($work['note'] ?? '') !== '')
                                            <span class="text-nicon-muted">— {{ $work['note'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (($job['floors'] ?? []) !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Bouwlaag / verdieping</div>
                            <p>{{ implode(', ', $job['floors']) }}</p>
                        </div>
                    @endif

                    @if (($job['rooms'] ?? []) !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Ruimtes</div>
                            <p>{{ implode(', ', $job['rooms']) }}</p>
                        </div>
                    @endif

                    @if (($job['notes'] ?? []) !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Opmerkingen</div>
                            @foreach ($job['notes'] as $note)
                                <p class="mt-1">{{ $note }}</p>
                            @endforeach
                        </div>
                    @endif

                    @if (($job['drawings'] ?? []) !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Tekeningen</div>
                            <ul class="mt-1 space-y-1">
                                @foreach ($job['drawings'] as $drawing)
                                    <li>
                                        <a href="{{ route('projects.documents.show', [$project, $drawing]) }}" class="text-nicon-orange">{{ $drawing->original_filename ?: 'Tekening' }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
                <div class="flex flex-col gap-2 border-t border-nicon-line p-4">
                    @if ($job['project_url'] ?? null)
                        <a href="{{ $job['project_url'] }}" class="bg-nicon-ink px-4 py-3 text-center text-sm font-medium text-white">Bekijk werk</a>
                    @endif
                    @if ($job['drawing_url'] ?? null)
                        <a href="{{ $job['drawing_url'] }}" class="border border-nicon-line px-4 py-3 text-center text-sm font-medium">Tekeningen</a>
                    @endif
                    @forelse ($job['tickets'] as $ticket)
                        @if ($job['is_work_ticket_holder'])
                            <a href="{{ route('work-tickets.show', $ticket) }}" class="bg-nicon-orange px-4 py-3 text-center text-sm font-medium text-white">Open {{ $ticket->kind->label() }} {{ $ticket->number }}</a>
                        @endif
                    @empty
                        @if ($job['werkbon_url'])
                            <a href="{{ $job['werkbon_url'] }}" class="bg-nicon-orange px-4 py-3 text-center text-sm font-medium text-white">Open werkbon</a>
                        @endif
                        @if ($job['opdrachtbon_url'])
                            <a href="{{ $job['opdrachtbon_url'] }}" class="bg-nicon-orange px-4 py-3 text-center text-sm font-medium text-white">Opdrachtbon</a>
                        @endif
                    @endforelse
                    @if ($job['maps_url'])
                        <a href="{{ $job['maps_url'] }}" class="border border-nicon-line px-4 py-3 text-center text-sm font-medium" target="_blank" rel="noopener noreferrer">Route</a>
                    @endif
                    @if (! empty($job['can_register_hours']))
                        @foreach ($job['hour_slots'] ?? [] as $slot)
                            <div class="border-t border-nicon-line px-1 pt-3">
                                @if (count($job['hour_slots']) > 1)
                                    <p class="mb-2 text-xs text-nicon-muted">{{ $slot['work_title'] }} · gepland {{ \App\Support\PlanningHours::hoursLabel($slot['planned_hours']) }}</p>
                                @endif
                                @include('vakman.partials.hours-form', [
                                    'date' => $detail['date']->toDateString(),
                                    'assignmentId' => $slot['assignment_id'],
                                    'projectId' => $slot['project_id'],
                                    'workItemId' => $slot['work_item_id'],
                                    'plannedHours' => $slot['planned_hours'],
                                    'entry' => $slot['entry'],
                                ])
                            </div>
                        @endforeach
                    @endif
                </div>
            </article>
        @empty
            <p class="mt-6 border border-nicon-line bg-white px-4 py-6 text-sm text-nicon-muted">
                Je staat deze dag niet ingepland.
            </p>
        @endforelse
        @if ($detail['unplanned_url'] ?? null)
            <a href="{{ $detail['unplanned_url'] }}" class="mt-4 block border border-nicon-line px-4 py-3 text-center text-sm">Uren op niet-gepland werk</a>
        @endif
    </div>
@endsection
