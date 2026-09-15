@php
    $catalog = $sourceCatalog ?? [];
@endphp
@can('update', $project)
    <details class="relative mt-2 max-w-xl text-xs">
        <summary class="cursor-pointer text-nicon-muted hover:text-nicon-ink">Bronbestanden</summary>
        <div class="absolute z-30 mt-1 w-[28rem] max-h-[70vh] overflow-auto border border-nicon-line bg-white p-3 shadow-sm space-y-3">
            <ul class="space-y-1 text-nicon-ink">
                @foreach ($catalog as $row)
                    <li>
                        @if ($row['current'])
                            {{ $row['label'] }} — {{ $row['version_word'] }} {{ $row['revision'] }} — {{ $row['date'] }}
                            <div class="text-[11px] text-nicon-muted">{{ $row['filename'] }}@if ($row['user']) · {{ $row['user'] }}@endif</div>
                        @else
                            <span class="text-nicon-muted">{{ $row['label'] }} — niet aanwezig</span>
                        @endif
                        @if ($row['history'] !== [])
                            <details class="mt-1">
                                <summary class="cursor-pointer text-nicon-muted">Vorige versies</summary>
                                <ul class="mt-1 space-y-1 pl-3">
                                    @foreach ($row['history'] as $old)
                                        <li class="flex items-center justify-between gap-2">
                                            <span>{{ $row['version_word'] }} {{ $old->revision }} — {{ $old->created_at?->format('d-m-Y') }} · {{ $old->original_filename }}</span>
                                            <span class="flex gap-1">
                                                <a href="{{ route('projects.documents.show', [$project, $old]) }}" class="text-nicon-orange-dark">Openen</a>
                                                @if ($row['type'] === 'plattegrond')
                                                    <form method="POST" action="{{ route('projects.sources.activate', [$project, $old]) }}">
                                                        @csrf
                                                        <button class="text-nicon-orange-dark">Activeren</button>
                                                    </form>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('projects.sources.store', $project) }}" enctype="multipart/form-data" class="space-y-2 border-t border-nicon-line pt-2">
                @csrf
                <label class="block text-[11px] uppercase tracking-wide text-nicon-muted">Nieuw bronbestand</label>
                <input type="file" name="files[]" multiple accept=".pdf,.csv,.txt,.xlsx,.xlsm,.xls,.jpg,.jpeg,.png,.webp" required class="w-full text-xs">
                <button class="border border-nicon-line bg-white px-3 py-1">Uploaden</button>
            </form>
        </div>
    </details>
@endcan
