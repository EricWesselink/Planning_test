@php
    /** @var \App\Models\Project $project */
    $href = $href ?? null;
    $extraClass = $extraClass ?? '';
    $numbers = $project->labeledNumbersLine();
    $title = $project->displayTitle();
@endphp
@if ($href)
    <a href="{{ $href }}" class="hover:text-nicon-orange {{ $extraClass }}">
        @if ($numbers !== '')
            <span class="block whitespace-nowrap">{{ $numbers }}</span>
        @endif
        @if ($title !== '')
            <span class="block">{{ $title }}</span>
        @endif
    </a>
@else
    <div class="{{ $extraClass }}">
        @if ($numbers !== '')
            <div class="whitespace-nowrap">{{ $numbers }}</div>
        @endif
        @if ($title !== '')
            <div>{{ $title }}</div>
        @endif
    </div>
@endif
