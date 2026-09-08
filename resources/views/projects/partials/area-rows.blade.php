<tr class="border-t border-nicon-line">
    <td class="px-3 py-2 whitespace-nowrap">{{ $area->displayNumber() }}</td>
    <td class="px-3 py-2">{{ $area->displayName() }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $area->squareMetersLabel() }}</td>
    <td class="px-3 py-2">
        @include('projects.partials.phase-check', [
            'area' => $area,
            'project' => $project,
            'defaultWorkerId' => $defaultWorkerId,
            'phase' => \App\Enums\WorkPhase::Egaliseren,
            'show' => $area->showsEgaliseren(),
            'hint' => null,
        ])
    </td>
    <td class="px-3 py-2">
        @include('projects.partials.phase-check', [
            'area' => $area,
            'project' => $project,
            'defaultWorkerId' => $defaultWorkerId,
            'phase' => \App\Enums\WorkPhase::Vloer,
            'show' => $area->showsVloer(),
            'hint' => $area->vloerLabel() !== 'Vloer' ? $area->vloerLabel() : null,
        ])
    </td>
    <td class="px-3 py-2">
        @include('projects.partials.phase-check', [
            'area' => $area,
            'project' => $project,
            'defaultWorkerId' => $defaultWorkerId,
            'phase' => \App\Enums\WorkPhase::Plinten,
            'show' => $area->showsPlinten(),
            'hint' => $area->showsPlinten() ? \App\Support\Format::qty($area->plintenQuantity()).' m¹' : null,
        ])
    </td>
    <td class="px-3 py-2 text-nicon-muted">{{ $area->doneByNames() }}</td>
</tr>
