@props([
    'id',
    'title',
    'badge' => null,
])

@php
    $expanded = filter_var($attributes->get('expanded', false), FILTER_VALIDATE_BOOLEAN);
    $attributes = $attributes->except(['expanded', 'open']);
@endphp

<details id="{{ $id }}" data-review-fold="{{ $id }}"@if ($expanded) open data-review-open="1"@endif {{ $attributes->merge(['class' => 'group border border-nicon-line bg-white']) }}>
    <summary class="flex cursor-pointer list-none items-center gap-2 px-5 py-4 font-semibold [&::-webkit-details-marker]:hidden">
        <span class="w-4 shrink-0 text-nicon-muted group-open:hidden" aria-hidden="true">▶</span>
        <span class="hidden w-4 shrink-0 text-nicon-muted group-open:inline" aria-hidden="true">▼</span>
        <span>{{ $title }}</span>
        @if (filled($badge))
            <span class="text-sm font-medium text-nicon-warn">{{ $badge }}</span>
        @endif
    </summary>
    <div class="space-y-3 border-t border-nicon-line px-5 py-4">
        {{ $slot }}
    </div>
</details>
