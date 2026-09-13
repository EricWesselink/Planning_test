@can('update', $worker)
    <form method="POST" action="{{ route('workers.active.update', $worker) }}" class="inline">
        @csrf
        @method('PATCH')
        <input type="hidden" name="active" value="{{ $worker->active ? '0' : '1' }}">
        <button class="text-xs text-nicon-muted hover:text-nicon-ink">{{ $worker->active ? 'Inactief zetten' : 'Actief maken' }}</button>
    </form>
@endcan
@can('delete', $worker)
    <form method="POST" action="{{ route('workers.destroy', $worker) }}" class="inline" onsubmit="return confirm({{ json_encode($worker->name.' wordt definitief verwijderd. Planning van dit team verdwijnt. Dit kan niet ongedaan worden gemaakt.') }})">
        @csrf
        @method('DELETE')
        <button class="text-xs text-nicon-danger hover:text-nicon-ink">Verwijderen</button>
    </form>
@endcan
