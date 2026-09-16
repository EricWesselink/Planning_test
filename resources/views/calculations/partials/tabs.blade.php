@php
    $tab = $tab ?? 'regels';
@endphp
<nav class="board-tabs {{ $compact ?? false ? '' : 'mt-3' }}">
    <a class="{{ $tab === 'board' ? 'is-on' : '' }}" href="{{ route('calculations.board', $calculation) }}">Calculatiebord</a>
    <a class="{{ $tab === 'regels' ? 'is-on' : '' }}" href="{{ route('calculations.show', $calculation) }}">Regels</a>
    <a class="{{ $tab === 'totals' ? 'is-on' : '' }}" href="{{ route('calculations.totals', $calculation) }}">Totalen</a>
    <a class="{{ $tab === 'files' ? 'is-on' : '' }}" href="{{ route('calculations.files', $calculation) }}">Bronbestanden</a>
</nav>
