@php
    $tab = $tab ?? 'teams';
@endphp
<nav class="board-tabs mt-4">
    <a class="{{ $tab === 'teams' ? 'is-on' : '' }}" href="{{ route('workers.index') }}">Teams</a>
    <a class="{{ $tab === 'personnel' ? 'is-on' : '' }}" href="{{ route('workers.personnel') }}">Personeel</a>
    <a class="{{ $tab === 'absence' ? 'is-on' : '' }}" href="{{ route('workers.absence') }}">Afwezigheid</a>
</nav>
