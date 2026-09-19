<div id="overleg" class="mt-8 max-w-3xl border border-nicon-line bg-white p-5">
    <h2 class="text-lg font-semibold">Overleg over aanvraag</h2>
    @forelse ($leaveRequest->messages as $message)
        <div class="mt-4 text-sm">
            <p class="text-xs text-nicon-muted">{{ $message->created_at?->format('d-m-Y H:i') }}</p>
            @if ($message->is_system)
                <p class="mt-1 text-nicon-muted">{{ $message->body }}</p>
            @else
                <p class="mt-1 font-medium">{{ $message->authorLabel() }}</p>
                <p class="mt-1 whitespace-pre-wrap">{{ $message->body }}</p>
            @endif
        </div>
    @empty
        <p class="mt-3 text-sm text-nicon-muted">Nog geen berichten.</p>
    @endforelse

    @can('message', $leaveRequest)
        <form method="POST" action="{{ $messageAction }}" class="mt-6 space-y-2">
            @csrf
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Bericht schrijven</label>
            <textarea name="body" rows="3" class="w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Bericht schrijven...">{{ old('body') }}</textarea>
            <button class="bg-nicon-orange text-white px-5 py-3 font-medium">{{ $submitLabel }}</button>
        </form>
    @endcan
</div>
