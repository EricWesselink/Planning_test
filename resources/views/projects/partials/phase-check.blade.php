@if ($show)
    @php
        $done = $area->phaseIsDone($phase);
        $who = $area->phaseWho($phase);
    @endphp
    <form method="POST" action="{{ route('projects.areas.tick', [$project, $area]) }}" class="js-tick-form">
        @csrf
        <input type="hidden" name="worker_id" class="js-tick-worker" value="{{ $defaultWorkerId }}">
        <input type="hidden" name="date" class="js-tick-date" value="{{ now()->toDateString() }}">
        <input type="hidden" name="phase" value="{{ $phase->value }}">
        <label class="inline-flex items-start gap-1.5 {{ $done ? '' : 'cursor-pointer' }}">
            <input
                type="checkbox"
                class="phase-check mt-0.5"
                @checked($done)
                @disabled($done)
                @if (! $done) onchange="this.form.requestSubmit()" @endif
            >
            <span>
                <span class="{{ $done ? 'text-nicon-ok' : '' }}">{{ $phase->label() }}</span>
                @if ($hint)
                    <span class="block text-[11px] text-nicon-muted leading-tight">{{ $hint }}</span>
                @endif
                @if ($done && $who)
                    <span class="block text-[11px] text-nicon-ok leading-tight">{{ $who }}</span>
                @endif
            </span>
        </label>
    </form>
@else
    <span class="text-nicon-muted">—</span>
@endif
